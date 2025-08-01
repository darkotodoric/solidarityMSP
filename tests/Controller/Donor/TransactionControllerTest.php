<?php

namespace App\Tests\Controller\Donor;

use App\DataFixtures\CityFixtures;
use App\DataFixtures\DamagedEducatorFixtures;
use App\DataFixtures\DamagedEducatorPeriodFixtures;
use App\DataFixtures\SchoolFixtures;
use App\DataFixtures\SchoolTypeFixtures;
use App\DataFixtures\TransactionFixtures;
use App\DataFixtures\UserDelegateRequestFixtures;
use App\DataFixtures\UserDelegateSchoolFixtures;
use App\DataFixtures\UserDonorFixtures;
use App\DataFixtures\UserFixtures;
use App\Repository\TransactionRepository;
use Liip\TestFixturesBundle\Services\DatabaseToolCollection;
use Liip\TestFixturesBundle\Services\DatabaseTools\AbstractDatabaseTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\User\UserInterface;

class TransactionControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private AbstractDatabaseTool $databaseTool;
    private ?TransactionRepository $transactionRepository;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();

        $this->databaseTool = $container->get(DatabaseToolCollection::class)->get();
        $this->loadFixtures();

        $this->transactionRepository = $container->get(TransactionRepository::class);
    }

    private function loadFixtures(): void
    {
        $this->databaseTool->loadFixtures([
            UserFixtures::class,
            CityFixtures::class,
            DamagedEducatorPeriodFixtures::class,
            SchoolTypeFixtures::class,
            SchoolFixtures::class,
            UserDelegateRequestFixtures::class,
            UserDelegateSchoolFixtures::class,
            UserDonorFixtures::class,
            DamagedEducatorFixtures::class,
            TransactionFixtures::class,
        ]);
    }

    private function getLoginUser(): ?UserInterface
    {
        return static::getContainer()->get('security.token_storage')->getToken()->getUser();
    }

    private function loginAsUserWithTransactions(): void
    {
        $transaction = $this->transactionRepository->findOneBy([]);
        $this->client->loginUser($transaction->getUser());
    }

    public function testProfileTransactions(): void
    {
        $this->loginAsUserWithTransactions();

        $this->client->request('GET', '/instrukcije-za-uplatu');
        $this->assertSelectorTextContains('h2', 'Instrukcije za uplatu');
        $this->assertEquals(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());

        $loginUser = $this->getLoginUser();
        $totalTransactions = count($loginUser->getTransactions());

        $this->assertSelectorTextSame('.total-results', 'Ukupno rezultata: '.$totalTransactions);
    }

    public function testTransactionCreate(): void
    {
        $this->loginAsUserWithTransactions();

        $this->client->request('GET', '/kreiraj-instrukcije-za-uplatu');
        $this->assertEquals(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
    }
}
