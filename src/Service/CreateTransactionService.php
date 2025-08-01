<?php

namespace App\Service;

use App\Entity\DamagedEducator;
use App\Entity\Transaction;
use App\Entity\User;
use App\Entity\UserDonor;
use App\Repository\DamagedEducatorRepository;
use App\Repository\TransactionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

class CreateTransactionService
{
    private $minTransactionDonationAmount = 500;

    public function __construct(private EntityManagerInterface $entityManager, private MailerInterface $mailer, private TransactionRepository $transactionRepository, private ParameterBagInterface $params, private DamagedEducatorRepository $damagedEducatorRepository)
    {
    }

    public function isHoliday(): bool
    {
        $dates = ['01.01', '02.01', '06.01', '07.01', '15.01', '16.01', '17.01', '20.01', '01.05', '02.05', '06.05', '06.12', '11.11', '25.12', '31.12'];

        return in_array(date('d.m'), $dates);
    }

    public function getDamagedEducators(): array
    {
        $transactionStatuses = [
            Transaction::STATUS_NEW,
            Transaction::STATUS_WAITING_CONFIRMATION,
            Transaction::STATUS_CONFIRMED,
            Transaction::STATUS_EXPIRED,
        ];

        $queryString = '';
        $queryString .= 'WHERE de.status = :status';

        $queryParameters = [];
        $queryParameters['status'] = DamagedEducator::STATUS_NEW;

        $stmt = $this->entityManager->getConnection()->executeQuery('
            SELECT de.id, de.period_id, de.account_number, de.amount, de.school_id,
               st.name AS school_type, s.have_payout_priority
            FROM damaged_educator AS de
             INNER JOIN damaged_educator_period AS dep ON dep.id = de.period_id AND dep.processing = 1
             INNER JOIN school AS s ON s.id = de.school_id AND s.processing = 1
             INNER JOIN school_type AS st ON st.id = s.type_id
             '.$queryString.'
            ', $queryParameters);

        $items = [];
        foreach ($stmt->fetchAllAssociative() as $item) {
            $transactionSum = $this->transactionRepository->getSumAmountForAccountNumber($item['period_id'], $item['account_number'], $transactionStatuses);
            $item['remainingAmount'] = $item['amount'] - $transactionSum;
            if ($item['remainingAmount'] < $this->minTransactionDonationAmount) {
                continue;
            }

            $items[$item['id']] = $item;
        }

        // Sort by priority
        uasort($items, function ($a, $b) {
            if ($a['have_payout_priority'] !== $b['have_payout_priority']) {
                return $b['have_payout_priority'] <=> $a['have_payout_priority'];
            }

            return $b['remainingAmount'] <=> $a['remainingAmount'];
        });

        return $items;
    }

    public function hasNotPaidTransactionsInLastDays(UserDonor $userDonor, int $days): bool
    {
        $qb = $this->entityManager->createQueryBuilder();

        $qb->select('COUNT(t.id)')
            ->from(Transaction::class, 't')
            ->where('t.user = :user')
            ->andWhere('t.status = :status')
            ->andWhere('t.createdAt > :dateLimit')
            ->setParameter('user', $userDonor->getUser())
            ->setParameter('status', Transaction::STATUS_NOT_PAID)
            ->setParameter('dateLimit', new \DateTime('-'.$days.' days'));

        return $qb->getQuery()->getSingleScalarResult() > 0;
    }

    public function getSumTransactions(UserDonor $userDonor): int
    {
        $qb = $this->entityManager->createQueryBuilder();

        $qb->select('SUM(t.amount)')
            ->from(Transaction::class, 't')
            ->where('t.user = :user')
            ->andWhere('t.status IN (:statuses)')
            ->andWhere('t.createdAt > :dateLimit')
            ->setParameter('user', $userDonor->getUser())
            ->setParameter('statuses', [
                Transaction::STATUS_NEW,
                Transaction::STATUS_WAITING_CONFIRMATION,
                Transaction::STATUS_CONFIRMED,
                Transaction::STATUS_EXPIRED,
            ])
            ->setParameter('dateLimit', new \DateTime('-30 days'));

        $sum = (int) $qb->getQuery()->getSingleScalarResult();
        $sumNotPaidButConfirmed = $this->getSumNotPaidButConfirmedTransactions($userDonor);

        return $sum + $sumNotPaidButConfirmed;
    }

    private function getSumNotPaidButConfirmedTransactions(UserDonor $userDonor): int
    {
        $qb = $this->entityManager->createQueryBuilder();

        $qb->select('SUM(t.amount)')
            ->from(Transaction::class, 't')
            ->where('t.user = :user')
            ->andWhere('t.status = :status')
            ->andWhere('t.userDonorConfirmed = 1')
            ->andWhere('t.createdAt > :dateLimit')
            ->setParameter('user', $userDonor->getUser())
            ->setParameter('status', Transaction::STATUS_NOT_PAID)
            ->setParameter('dateLimit', new \DateTime('-30 days'));

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    public function sendNewTransactionEmail(UserDonor $userDonor): void
    {
        $message = (new TemplatedEmail())
            ->to($userDonor->getUser()->getEmail())
            ->from(new Address('donatori@mrezasolidarnosti.org', 'Mreža Solidarnosti'))
            ->subject('Stigle su ti nove instrukcije za uplatu')
            ->htmlTemplate('email/donor-new-transactions.html.twig')
            ->context([
                'user' => $userDonor->getUser(),
            ]);

        try {
            $this->mailer->send($message);
        } catch (\Exception $exception) {
        }
    }

    public function sumTransactionsToEducator(User $user, string $accountNumber): int
    {
        $transactionStatuses = [
            Transaction::STATUS_NEW,
            Transaction::STATUS_WAITING_CONFIRMATION,
            Transaction::STATUS_CONFIRMED,
            Transaction::STATUS_EXPIRED,
        ];

        $stmt = $this->entityManager->getConnection()->executeQuery('
            SELECT SUM(t.amount)
            FROM transaction AS t
            WHERE t.user_id = :userId
             AND t.account_number = :accountNumber
             AND t.status IN ('.implode(',', $transactionStatuses).')
             AND t.created_at > DATE(NOW() - INTERVAL 1 YEAR)
            ', [
            'userId' => $user->getId(),
            'accountNumber' => $accountNumber,
        ]);

        return (int) $stmt->fetchOne();
    }

    public function wontToDonateToSchool(int $donorSchoolType, string $schoolType): bool
    {
        $isUniversity = $this->isUniversity($schoolType);
        if (UserDonor::SCHOOL_TYPE_UNIVERSITY == $donorSchoolType && !$isUniversity) {
            return false;
        }

        if (UserDonor::SCHOOL_TYPE_EDUCATION == $donorSchoolType && $isUniversity) {
            return false;
        }

        return true;
    }

    private function isUniversity(string $schoolType): bool
    {
        if (preg_match('/Univerzitet|Akademija/i', $schoolType)) {
            return true;
        }

        return false;
    }

    public function create(User $user, int $amount, int $donorSchoolType): bool
    {
        $minTransactionDonationAmount = $this->minTransactionDonationAmount;
        if ($amount > 100000) {
            $minTransactionDonationAmount = 10000;
        }

        if ($amount < $minTransactionDonationAmount) {
            return false;
        }

        $maxYearDonationAmount = 50000;
        $damagedEducators = $this->getDamagedEducators();
        $transactionCreated = false;

        foreach ($damagedEducators as $damagedEducator) {
            if ($amount < $minTransactionDonationAmount) {
                break;
            }

            if (!$this->wontToDonateToSchool($donorSchoolType, $damagedEducator['school_type'])) {
                continue;
            }

            if ($damagedEducator['remainingAmount'] < $minTransactionDonationAmount) {
                continue;
            }

            if ($damagedEducator['remainingAmount'] > $maxYearDonationAmount) {
                $damagedEducator['remainingAmount'] = $maxYearDonationAmount;
            }

            $sumTransactionAmount = $this->sumTransactionsToEducator($user, $damagedEducator['account_number']);
            $transactionAmount = $amount > $damagedEducator['remainingAmount'] ? $damagedEducator['remainingAmount'] : $amount;
            if (($sumTransactionAmount + $transactionAmount) >= $maxYearDonationAmount) {
                continue;
            }

            $transaction = new Transaction();
            $transaction->setUser($user);

            $entity = $this->damagedEducatorRepository->find($damagedEducator['id']);
            $transaction->setDamagedEducator($entity);
            $transaction->setAccountNumber($damagedEducator['account_number']);

            $transaction->setAmount($transactionAmount);
            $amount -= $transaction->getAmount();

            $this->entityManager->persist($transaction);
            $this->entityManager->flush();

            $transactionCreated = true;
        }

        return $transactionCreated;
    }
}
