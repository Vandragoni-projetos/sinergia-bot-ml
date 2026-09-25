<?php

declare(strict_types=1);

namespace Sinergia\Tests\Integration;

use DI\Container;
use Sinergia\Application\Auth\PasswordHasher;
use Sinergia\Application\Niche\NicheFilters;
use Sinergia\Application\Offer\SelectionReport;
use Sinergia\Application\Offer\SelectOffers;
use Sinergia\Application\Port\MercadoLivre\TokenSet;
use Sinergia\Domain\Installation\Installation;
use Sinergia\Infrastructure\Crypto\SecretBox;
use Sinergia\Infrastructure\Persistence\AccountNicheRepository;
use Sinergia\Infrastructure\Persistence\InstallationRepository;
use Sinergia\Infrastructure\Persistence\MlCredentialRepository;
use Sinergia\Infrastructure\Persistence\OfferSelectionRepository;
use Sinergia\Infrastructure\Persistence\UserRepository;
use Sinergia\Kernel;
use Sinergia\Shared\Config\Config;
use Sinergia\Shared\Config\SensitiveValue;
use Sinergia\Tests\Support\RoutedMercadoLivreHttp;

/**
 * Etapa 4: seletor de ofertas com o container real, MariaDB de teste e HTTP do Mercado Livre FALSO.
 * Os tokens abaixo são textos fictícios (nenhuma credencial real é usada).
 */
final class OfferSelectionTest extends DatabaseTestCase
{
    private const string TOKEN_A = 'TESTE-token-ficticio-conta-A';
    private const string TOKEN_B = 'TESTE-token-ficticio-conta-B';

    private \PDO $db;
    private Container $container;
    private RoutedMercadoLivreHttp $ml;
    private Installation $a;
    private Installation $b;
    private int $userA;
    private int $userB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = $this->freshSchema();
        $installations = new InstallationRepository($this->db);
        $this->a = $installations->ensure('conta-a', 'Loja A', 'MLB');
        $this->b = $installations->ensure('conta-b', 'Loja B', 'MLB');
        $users = new UserRepository($this->db);
        $hash = (new PasswordHasher())->hash(new SensitiveValue('senha-qualquer-123'));
        $this->userA = $users->create($this->a->id, 'ana@loja-a.test', 'Ana', $hash);
        $this->userB = $users->create($this->b->id, 'bia@loja-b.test', 'Bia', $hash);

        $env = array_filter(array_merge($_ENV, getenv()), 'is_string');
        $container = Kernel::container(Config::fromArray([
            'APP_ENV' => 'test',
            'APP_KEY' => SecretBox::generateKeyBase64(),
            'DB_HOST' => (string) ($env['TEST_DB_HOST'] ?? ''),
            'DB_PORT' => (string) ($env['TEST_DB_PORT'] ?? '3306'),
            'DB_DATABASE' => (string) ($env['TEST_DB_DATABASE'] ?? ''),
            'DB_USERNAME' => (string) ($env['TEST_DB_USERNAME'] ?? ''),
            'DB_PASSWORD' => (string) ($env['TEST_DB_PASSWORD'] ?? ''),
            'ML_CLIENT_ID' => '1234567890123456',
            'ML_CLIENT_SECRET' => 'segredo-ficticio-de-teste',
            'ML_REDIRECT_URI' => 'https://app.example.test/oauth/mercadolivre/callback',
        ]), dirname(__DIR__, 2));
        self::assertInstanceOf(Container::class, $container);
        $this->container = $container;
        $this->ml = new RoutedMercadoLivreHttp();
        $this->container->set('ml.http', $this->ml->client());

        foreach ([[$this->a, self::TOKEN_A, 111], [$this->b, self::TOKEN_B, 222]] as [$installation, $token, $mlUser]) {
            $this->container->get(MlCredentialRepository::class)->save($installation->id, '1234567890123456', new TokenSet(
                new SensitiveValue($token), new SensitiveValue($token . '-refresh'), 21600, 'offline_access read', $mlUser, 'Bearer',
            ), new \DateTimeImmutable('now'));
        }
    }

    public function testSelectionAppliesCurationDeduplicationFiltersAndOfferRule(): void
    {
        $this->chooseA();
        $this->routeCasaCozinha();

        $report = $this->select($this->a);

        self::assertSame(SelectionReport::COMPLETED, $report->status);
        self::assertSame(['MLB120', 'MLB110', 'MLB100'], $this->candidateIds($report));
        $stats = $report->stats;
        self::assertSame([
            'discount_below_min' => 2,       // MLB103 (sem preço anterior → 0%) e MLB113 (4%)
            'domain_mismatch' => 1,          // MLB101: AIR_MATTRESSES dentro de "Potes para Alimentos" (desvio real da curadoria)
            'no_photo' => 1,                 // MLB102
            'not_catalog_product' => 2,      // MLBU200 (USER_PRODUCT) e MLB300 (ITEM)
            'price_above_max' => 1,          // MLB111 (R$ 800)
            'price_below_min' => 1,          // MLB112 (R$ 20)
        ], $stats['rejections']);
        self::assertSame(1, $stats['duplicates'], 'MLB110 aparece em duas categorias de Panelas.');
        self::assertContains('MLB456045', $stats['low_volume_categories']);

        // Chamadas: só os três endpoints oficiais; nunca /items/{id}, /user-products, MLBU ou busca.
        foreach ($this->ml->paths() as $path) {
            self::assertMatchesRegularExpression('#^/(highlights/MLB/category/MLB\d+|products/MLB\d+(/items)?)$#', $path);
        }
        self::assertSame(1, $this->ml->count('/products/MLB110'), 'Produto repetido é consultado uma vez.');
        self::assertSame(0, $this->ml->count('/products/MLB101/items'), 'Domínio incompatível não chega a consultar ofertas.');
        self::assertSame(0, $this->ml->count('/products/MLB120/items'), 'Com buy_box_winner não é preciso listar ofertas.');
        self::assertSame([self::TOKEN_A], array_values(array_unique(array_column($this->ml->requests, 'token'))));

        // Regra de oferta e atribuição do duplicado (posição 1 em "Caçarolas" vence a posição 2 em "Jogo de Panelas").
        $rows = $this->rows('SELECT ml_product_id, ml_category_id, ranking_position, item_id, price, original_price, discount_pct, selection_rule
            FROM account_offer_candidates WHERE installation_id = ? ORDER BY sort_order', [$this->a->id->value]);
        self::assertSame([
            ['MLB120', 'MLB456045', '1', 'MLB7693138348', '161.00', '219.90', '26', 'buy_box'],
            ['MLB110', 'MLB107501', '1', 'MLB7000000001', '200.00', '250.00', '20', 'lowest_price'],
            ['MLB100', 'MLB244658', '1', 'MLB7000000001', '99.90', '149.90', '33', 'lowest_price'],
        ], $rows);

        // Dados públicos cacheados sem nenhuma referência à conta.
        self::assertSame(['ok'], array_values(array_unique(array_column($this->rows('SELECT status FROM ml_products'), 0))));
        foreach (['ml_ranking_snapshots', 'ml_ranking_entries', 'ml_products', 'ml_product_offers'] as $table) {
            $columns = array_column($this->rows('SHOW COLUMNS FROM ' . $table), 0);
            self::assertNotContains('installation_id', $columns, $table . ' é público.');
        }
        $json = (string) $this->db->query('SELECT GROUP_CONCAT(stats_json) FROM offer_selection_runs')->fetchColumn();
        self::assertStringNotContainsString('TESTE-token', $json);
    }

    public function testCurationDeviationsAreDiscardedEvenFromApprovedCategories(): void
    {
        // Os três desvios reais da amostra de 2026-09-25, cada um dentro de uma categoria APROVADA.
        $this->choose($this->a, 'casa-cozinha', ['organizacao-cozinha'], new NicheFilters(null, null, null, false));
        $this->choose($this->a, 'beleza-cuidados', ['pele'], new NicheFilters(null, null, null, false));
        $this->ml->ranking('MLB244658', [['MLB101'], ['MLB100']])
            ->ranking('MLB271673', [['MLB104']])
            ->ranking('MLB277460', [])
            ->ranking('MLB199648', [['MLB131'], ['MLB130']])
            ->ranking('MLB264874', []);
        $this->ml->product('MLB101', 'MLB-AIR_MATTRESSES')->offers('MLB101', [['price' => 100]])
            ->product('MLB100', 'MLB-FOOD_STORAGE_CONTAINERS')->offers('MLB100', [['price' => 50]])
            ->product('MLB104', 'MLB-FLATWARE_KITS')->offers('MLB104', [['price' => 80]])
            ->product('MLB131', 'MLB-WATERPROOFING_PROTECTORS_AND_SURFACE_TREATMENTS')->offers('MLB131', [['price' => 60]])
            ->product('MLB130', 'MLB-SUNSCREENS')->offers('MLB130', [['price' => 45]]);

        $report = $this->select($this->a);

        self::assertSame(['MLB100', 'MLB130'], $this->candidateIds($report));
        self::assertSame(['domain_mismatch' => 3], $report->stats['rejections']);
        foreach (['MLB101', 'MLB104', 'MLB131'] as $id) {
            self::assertSame(0, $this->ml->count('/products/' . $id . '/items'));
        }
    }

    public function testFiltersArePerAccountAndMissingOriginalPriceIsHandled(): void
    {
        $this->chooseA();
        // B: mesmas categorias de Organização, sem filtro de preço/desconto e foto opcional.
        $this->choose($this->b, 'casa-cozinha', ['organizacao-cozinha'], new NicheFilters(null, null, null, false));
        $this->routeCasaCozinha();

        $this->select($this->a);
        $reportB = $this->select($this->b);

        // B aceita o produto sem foto (MLB102) e o sem preço anterior (MLB103, 0% de desconto, preço original nulo).
        self::assertSame(['MLB100', 'MLB103', 'MLB102'], $this->candidateIds($reportB));
        self::assertSame(['MLB103', null, '0'], $this->rows(
            'SELECT ml_product_id, original_price, discount_pct FROM account_offer_candidates WHERE installation_id = ? AND ml_product_id = ?',
            [$this->b->id->value, 'MLB103'],
        )[0]);
        self::assertSame(['domain_mismatch' => 1, 'not_catalog_product' => 2], $reportB->stats['rejections']);
    }

    public function testAccountsAreIsolatedButShareThePublicCache(): void
    {
        $this->chooseA();
        $this->choose($this->b, 'casa-cozinha', ['organizacao-cozinha'], new NicheFilters(null, null, null, false));
        $this->routeCasaCozinha();

        $this->select($this->a);
        $aRows = $this->rows('SELECT * FROM account_offer_candidates WHERE installation_id = ? ORDER BY ml_product_id', [$this->a->id->value]);
        $aRuns = $this->rows('SELECT * FROM offer_selection_runs WHERE installation_id = ?', [$this->a->id->value]);
        $this->ml->reset();

        $reportB = $this->select($this->b);

        // B usa SÓ o token de B e SÓ as escolhas de B (nada de Panelas/Air fryers, que são de A).
        self::assertSame([self::TOKEN_B], array_values(array_unique(array_column($this->ml->requests, 'token'))));
        self::assertNotContains('MLB110', $this->candidateIds($reportB));
        self::assertNotContains('MLB120', $this->candidateIds($reportB));
        self::assertSame(0, $this->ml->count('/highlights/MLB/category/MLB107501'));
        // Cache público compartilhado: o ranking que A buscou não é buscado de novo por B.
        self::assertSame(0, $this->ml->count('/highlights/MLB/category/MLB244658'));
        // A execução de B não altera nada de A.
        self::assertSame($aRows, $this->rows('SELECT * FROM account_offer_candidates WHERE installation_id = ? ORDER BY ml_product_id', [$this->a->id->value]));
        self::assertSame($aRuns, $this->rows('SELECT * FROM offer_selection_runs WHERE installation_id = ?', [$this->a->id->value]));

        $repo = $this->container->get(OfferSelectionRepository::class);
        self::assertSame(['MLB120', 'MLB110', 'MLB100'], array_column($repo->latestCandidates($this->a->id), 'product_id'));
        self::assertSame(['MLB100', 'MLB103', 'MLB102'], array_column($repo->latestCandidates($this->b->id), 'product_id'));
        // Uma conta sem escolhas não recebe nada, mesmo com o cache cheio.
        $installations = new InstallationRepository($this->db);
        $c = $installations->ensure('conta-c', 'Loja C', 'MLB');
        self::assertSame(SelectionReport::NO_SELECTION, $this->select($c)->status);
        self::assertSame([], $repo->latestCandidates($c->id));
    }

    public function testSecondRunWithinTtlUsesOnlyTheCache(): void
    {
        $this->chooseA();
        $this->routeCasaCozinha();
        $first = $this->select($this->a);
        $this->ml->reset();

        $second = $this->select($this->a);

        self::assertSame(0, $second->apiCalls);
        self::assertSame([], $this->ml->requests);
        self::assertSame($this->candidateIds($first), $this->candidateIds($second));
    }

    public function testUnauthorizedStopsTheRunAndKeepsPreviousCandidates(): void
    {
        $this->choose($this->a, 'casa-cozinha', ['air-fryers'], NicheFilters::defaults());
        $this->ml->ranking('MLB456045', [['MLB120'], ['MLB121']]);
        $this->ml->product('MLB120', 'MLB-AIR_FRYERS')->offers('MLB120', [['price' => 300]])
            ->product('MLB121', 'MLB-AIR_FRYERS')->offers('MLB121', [['price' => 350]]);
        self::assertSame(['MLB120', 'MLB121'], $this->candidateIds($this->select($this->a)));

        $this->db->exec('DELETE FROM ml_ranking_snapshots');
        $this->ml->on('/highlights/MLB/category/MLB456045', 401, ['message' => 'invalid access token', 'error' => 'unauthorized', 'status' => 401]);
        $this->ml->reset();
        $report = $this->select($this->a);

        self::assertSame(SelectionReport::AUTH_FAILED, $report->status);
        self::assertSame([], $report->candidates);
        self::assertCount(1, $this->ml->requests, 'Nenhuma chamada depois do 401.');
        self::assertSame(['MLB120', 'MLB121'], array_column($this->container->get(OfferSelectionRepository::class)->latestCandidates($this->a->id), 'product_id'));
    }

    public function testForbiddenCategoryAndProductAreSkipped(): void
    {
        $this->choose($this->a, 'casa-cozinha', ['air-fryers', 'panelas'], NicheFilters::defaults());
        $this->ml->on('/highlights/MLB/category/MLB456045', 403, ['message' => 'forbidden', 'status' => 403])
            ->ranking('MLB107501', [['MLB140'], ['MLB141']])
            ->ranking('MLB107564', []);
        $this->ml->product('MLB140', 'MLB-KITCHEN_POTS')->offers('MLB140', [['price' => 90]])
            ->on('/products/MLB141', 403, ['message' => 'forbidden', 'status' => 403]);

        $report = $this->select($this->a);

        self::assertSame(SelectionReport::PARTIAL, $report->status);
        self::assertSame(['MLB140'], $this->candidateIds($report));
        self::assertSame(['forbidden' => 1], $report->stats['categories_failed']);
        self::assertSame(['product_unavailable' => 1], $report->stats['rejections']);
        self::assertSame([['forbidden']], $this->rows("SELECT status FROM ml_products WHERE ml_product_id = 'MLB141'"), 'Cache negativo.');
    }

    public function testRateLimitStopsFurtherCalls(): void
    {
        $this->choose($this->a, 'casa-cozinha', ['air-fryers'], NicheFilters::defaults());
        $this->ml->ranking('MLB456045', [['MLB150'], ['MLB151'], ['MLB152']]);
        $this->ml->on('/products/MLB150', 429, ['message' => 'too many requests', 'status' => 429])
            ->product('MLB151', 'MLB-AIR_FRYERS')->offers('MLB151', [['price' => 100]])
            ->product('MLB152', 'MLB-AIR_FRYERS')->offers('MLB152', [['price' => 100]]);

        $report = $this->select($this->a);

        self::assertSame(SelectionReport::PARTIAL, $report->status);
        self::assertTrue($report->stats['rate_limited']);
        self::assertSame(['/highlights/MLB/category/MLB456045', '/products/MLB150'], $this->ml->paths(), 'Depois do 429 nada mais é chamado.');
        self::assertSame(['product_unavailable' => 3], $report->stats['rejections']);
        self::assertSame(2, $report->apiCalls);
    }

    public function testServerErrorUsesStaleRankingOnlyWithinLimit(): void
    {
        $this->choose($this->a, 'casa-cozinha', ['air-fryers'], NicheFilters::defaults());
        $this->ml->ranking('MLB456045', [['MLB120']]);
        $this->ml->product('MLB120', 'MLB-AIR_FRYERS')->offers('MLB120', [['price' => 300]]);
        $this->select($this->a);

        $this->ml->on('/highlights/MLB/category/MLB456045', 503, ['message' => 'service unavailable', 'status' => 503]);
        $this->db->exec('UPDATE ml_ranking_snapshots SET fetched_at = NOW(3) - INTERVAL 7 HOUR');
        $stale = $this->select($this->a);
        self::assertSame(SelectionReport::PARTIAL, $stale->status);
        self::assertSame(1, $stale->stats['categories_stale']);
        self::assertSame(['MLB120'], $this->candidateIds($stale));

        $this->db->exec('UPDATE ml_ranking_snapshots SET fetched_at = NOW(3) - INTERVAL 49 HOUR');
        $failed = $this->select($this->a);
        self::assertSame(['server_error' => 1], $failed->stats['categories_failed']);
        self::assertSame([], $failed->candidates);
    }

    public function testMissingCredentialAbortsWithoutUsingAnotherAccountsToken(): void
    {
        $this->choose($this->a, 'casa-cozinha', ['air-fryers'], NicheFilters::defaults());
        $this->ml->ranking('MLB456045', [['MLB120']]);
        $this->db->prepare('DELETE FROM ml_credentials WHERE installation_id = ?')->execute([$this->a->id->value]);

        $report = $this->select($this->a);

        self::assertSame(SelectionReport::AUTH_FAILED, $report->status);
        self::assertSame([], $this->ml->requests, 'Sem credencial própria, nenhuma chamada (nunca com o token de B).');
    }

    private function chooseA(): void
    {
        // A: desconto ≥ 10%, R$ 30 a R$ 500, exige foto.
        $this->choose($this->a, 'casa-cozinha', ['air-fryers', 'panelas', 'organizacao-cozinha'], new NicheFilters(10, 3000, 50000, true));
    }

    /** @param list<string> $subniches */
    private function choose(Installation $installation, string $niche, array $subniches, NicheFilters $filters): void
    {
        $nicheId = (int) $this->rows('SELECT id FROM niches WHERE slug = ?', [$niche])[0][0];
        $ids = [];
        foreach ($subniches as $slug) {
            $ids[] = (int) $this->rows('SELECT id FROM subniches WHERE niche_id = ? AND slug = ?', [$nicheId, $slug])[0][0];
        }
        $user = $installation->id->equals($this->a->id) ? $this->userA : $this->userB;
        (new AccountNicheRepository($this->db))->save($installation->id, $nicheId, $ids, $filters, $user, new \DateTimeImmutable('now'));
    }

    private function routeCasaCozinha(): void
    {
        $this->ml
            ->ranking('MLB244658', [['MLB100'], ['MLB101'], ['MLBU200', 'USER_PRODUCT'], ['MLB300', 'ITEM']])
            ->ranking('MLB277460', [['MLB102']])
            ->ranking('MLB271673', [['MLB103']])
            ->ranking('MLB107501', [['MLB110'], ['MLB111'], ['MLB112']])
            ->ranking('MLB107564', [['MLB113'], ['MLB110']])
            ->ranking('MLB456045', [['MLB120']]);
        $this->ml
            ->product('MLB100', 'MLB-FOOD_STORAGE_CONTAINERS')
            ->offers('MLB100', [['price' => 120, 'original_price' => 200], ['price' => 99.9, 'original_price' => 149.9]])
            ->product('MLB101', 'MLB-AIR_MATTRESSES')
            ->product('MLB102', 'MLB-KITCHEN_CABINET_ORGANIZERS', ['pictures' => []])
            ->offers('MLB102', [['price' => 60, 'original_price' => 80]])
            ->product('MLB103', 'MLB-FLATWARE_ORGANIZERS')
            ->offers('MLB103', [['price' => 50]])
            ->product('MLB110', 'MLB-KITCHEN_POTS')
            ->offers('MLB110', [['price' => 250, 'original_price' => 300], ['price' => 200, 'original_price' => 250]])
            ->product('MLB111', 'MLB-KITCHEN_POTS')
            ->offers('MLB111', [['price' => 800, 'original_price' => 1000]])
            ->product('MLB112', 'MLB-KITCHEN_POTS')
            ->offers('MLB112', [['price' => 20, 'original_price' => 40]])
            ->product('MLB113', 'MLB-KITCHEN_COOKWARE_KITS')
            ->offers('MLB113', [['price' => 100, 'original_price' => 105]])
            ->product('MLB120', 'MLB-AIR_FRYERS', ['buy_box_winner' => [
                'item_id' => 'MLB7693138348', 'price' => 161, 'original_price' => 219.9, 'currency_id' => 'BRL', 'condition' => 'new',
            ]]);
    }

    private function select(Installation $installation): SelectionReport
    {
        return $this->container->get(SelectOffers::class)->run($installation->id);
    }

    /** @return list<string> */
    private function candidateIds(SelectionReport $report): array
    {
        return array_map(static fn ($c): string => $c->product->id, $report->candidates);
    }

    /**
     * @param list<mixed> $params
     *
     * @return list<list<?string>>
     */
    private function rows(string $sql, array $params = []): array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return array_map(
            static fn (array $row): array => array_map(static fn (mixed $v): ?string => $v === null ? null : (string) $v, $row),
            $stmt->fetchAll(\PDO::FETCH_NUM),
        );
    }
}
