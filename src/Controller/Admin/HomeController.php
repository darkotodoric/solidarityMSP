<?php

namespace App\Controller\Admin;

use App\Entity\DamagedEducatorPeriod;
use App\Entity\Transaction;
use App\Repository\DamagedEducatorRepository;
use App\Repository\TransactionRepository;
use App\Repository\UserDelegateSchoolRepository;
use App\Repository\UserDonorRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin', name: 'admin_')]
final class HomeController extends AbstractController
{
    #[Route('/', name: 'home')]
    public function index(EntityManagerInterface $entityManager, UserDonorRepository $userDonorRepository, UserRepository $userRepository, UserDelegateSchoolRepository $userDelegateSchoolRepository, TransactionRepository $transactionRepository, DamagedEducatorRepository $damagedEducatorRepository): Response
    {
        $totalDonors = $userDonorRepository->getTotal();
        $totalActiveDonors = $transactionRepository->getTotalActiveDonors(false);
        $sumAmountMonthlyDonors = $userDonorRepository->sumAmountMonthlyDonors();
        $totalDelegates = $userRepository->getTotalDelegates();
        $totalAdmins = $userRepository->getTotalAdmins();

        $period = $entityManager->getRepository(DamagedEducatorPeriod::class)->findAll();
        $periodItems = [];

        foreach ($period as $pData) {
            $sumAmountDamagedEducators = $damagedEducatorRepository->getSumAmountByPeriod($pData, null);
            $sumAmountNewTransactions = $transactionRepository->getSumAmountTransactions($pData, null, [Transaction::STATUS_NEW]);
            $sumAmountWaitingConfirmationTransactions = $transactionRepository->getSumAmountTransactions($pData, null, [Transaction::STATUS_WAITING_CONFIRMATION, Transaction::STATUS_EXPIRED]);
            $sumAmountConfirmedTransactions = $transactionRepository->getSumAmountTransactions($pData, null, [Transaction::STATUS_CONFIRMED]);
            $totalDamagedEducators = $damagedEducatorRepository->getTotalsByPeriod($pData, null);
            $averageAmountPerDamagedEducator = 0;

            if ($sumAmountConfirmedTransactions > 0 && $totalDamagedEducators > 0) {
                $averageAmountPerDamagedEducator = floor($sumAmountConfirmedTransactions / $totalDamagedEducators);
            }

            $periodItems[] = [
                'entity' => $pData,
                'totalDamagedEducators' => $totalDamagedEducators,
                'sumAmountDamagedEducators' => $sumAmountDamagedEducators,
                'sumAmountNewTransactions' => $sumAmountNewTransactions,
                'sumAmountWaitingConfirmationTransactions' => $sumAmountWaitingConfirmationTransactions,
                'sumAmountConfirmedTransactions' => $sumAmountConfirmedTransactions,
                'averageAmountPerDamagedEducator' => $averageAmountPerDamagedEducator,
            ];
        }

        return $this->render('admin/home/index.html.twig', [
            'totalDonors' => $totalDonors,
            'totalActiveDonors' => $totalActiveDonors,
            'sumAmountMonthlyDonors' => $sumAmountMonthlyDonors,
            'totalDelegate' => $totalDelegates,
            'totalAdmins' => $totalAdmins,
            'periodItems' => $periodItems,
        ]);
    }
}
