<?php

declare(strict_types=1);

namespace Sinergia\Tests\Integration;

use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Sinergia\Application\Auth\PasswordHasher;
use Sinergia\Domain\Installation\Installation;
use Sinergia\Infrastructure\Crypto\SecretBox;
use Sinergia\Infrastructure\Persistence\InstallationRepository;
use Sinergia\Infrastructure\Persistence\UserRepository;
use Sinergia\Kernel;
use Sinergia\Shared\Config\Config;
use Sinergia\Shared\Config\SensitiveValue;
use Sinergia\Tests\Support\PanelRequests;
use Sinergia\Web\HttpApp;

/**
 * Etapa 3: tela Nichos (catálogo global curado × preferências privadas por conta).
 * Container real do Kernel + MariaDB de teste com as migrations 0005/0006.
 */
final class PanelNichesTest extends DatabaseTestCase
{
    use PanelRequests;

    private const string PASSWORD = 'senha-do-painel-123';

    private \PDO $db;
    private Installation $a;
    private Installation $b;
    private int $ana;
    private int $carla;
    private int $bia;
    private App $app;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = $this->freshSchema();
        $installations = new InstallationRepository($this->db);
        $this->a = $installations->ensure('conta-a', 'Loja A', 'MLB');
        $this->b = $installations->ensure('conta-b', 'Loja B', 'MLB');
        $hasher = new PasswordHasher();
        $users = new UserRepository($this->db);
        $hash = $hasher->hash(new SensitiveValue(self::PASSWORD));
        $this->ana = $users->create($this->a->id, 'ana@loja-a.test', 'Ana', $hash);
        $this->carla = $users->create($this->a->id, 'carla@loja-a.test', 'Carla', $hash);
        $this->bia = $users->create($this->b->id, 'bia@loja-b.test', 'Bia', $hash);

        $env = array_filter(array_merge($_ENV, getenv()), 'is_string');
        $container = Kernel::container(Config::fromArray([
            'APP_ENV' => 'test',
            'APP_KEY' => SecretBox::generateKeyBase64(),
            'DB_HOST' => (string) ($env['TEST_DB_HOST'] ?? ''),
            'DB_PORT' => (string) ($env['TEST_DB_PORT'] ?? '3306'),
            'DB_DATABASE' => (string) ($env['TEST_DB_DATABASE'] ?? ''),
            'DB_USERNAME' => (string) ($env['TEST_DB_USERNAME'] ?? ''),
            'DB_PASSWORD' => (string) ($env['TEST_DB_PASSWORD'] ?? ''),
        ]), dirname(__DIR__, 2));
        $this->app = HttpApp::create($container);
    }

    protected function panelApp(): App
    {
        return $this->app;
    }

    public function testPageShowsCuratedCatalogWithFriendlyNamesOnly(): void
    {
        $response = $this->httpGet('/nichos', ['sbm_session' => $this->signIn('ana@loja-a.test', self::PASSWORD)]);
        $body = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        foreach (['Casa e cozinha', 'Beleza e cuidados', 'Eletrônicos', 'Esporte e saúde', 'Air fryers', 'Organização da cozinha', 'Treino em casa'] as $name) {
            self::assertStringContainsString($name, $body);
        }
        self::assertStringContainsString('0 de 5 ativos', $body);
        self::assertStringContainsString('Desconto mínimo (%)', $body);
        self::assertStringContainsString('Só produtos com foto', $body);
        // Nenhum ID técnico do Mercado Livre (categoria MLB123 / domínio MLB-XYZ) chega ao cliente.
        self::assertDoesNotMatchRegularExpression('/MLB[-\d]/', $body);
        self::assertStringNotContainsString('Geladeiras', $body);
    }

    public function testSavePersistsSubnichesAndFiltersForTheAuthenticatedAccountAndUser(): void
    {
        $session = $this->signIn('ana@loja-a.test', self::PASSWORD);

        $response = $this->save($session, 'casa-cozinha', ['air-fryers', 'panelas'], ['desconto_minimo' => '15', 'preco_minimo' => '30', 'preco_maximo' => '1.299,90', 'exige_foto' => '1']);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/nichos?salvo=casa-cozinha#nicho-casa-cozinha', $response->getHeaderLine('Location'));
        self::assertSame(
            [['15', '30.00', '1299.90', '1', (string) $this->ana]],
            $this->fetch('SELECT min_discount_pct, min_price, max_price, require_photo, updated_by_user_id FROM account_niches WHERE installation_id = ?', [$this->a->id->value]),
        );
        self::assertSame(['air-fryers', 'panelas'], $this->activeSlugs($this->a));

        $page = (string) $this->httpGet('/nichos', ['sbm_session' => $session], ['salvo' => 'casa-cozinha'])->getBody();
        self::assertStringContainsString('Casa e cozinha: escolhas salvas.', $page);
        self::assertStringContainsString('2 de 5 ativos', $page);
        self::assertStringContainsString('Desconto ≥ 15% · R$ 30 a 1.299,90 · com foto', $page);
        self::assertMatchesRegularExpression('/value="air-fryers" checked/', $page);
        self::assertMatchesRegularExpression('/value="cafeteiras">/', $page);
        self::assertStringContainsString('value="1.299,90"', $page);

        // Outro usuário da MESMA conta edita: a autoria muda, a conta continua a mesma.
        $carla = $this->signIn('carla@loja-a.test', self::PASSWORD);
        $this->save($carla, 'casa-cozinha', ['air-fryers', 'panelas', 'cafeteiras'], ['desconto_minimo' => '15', 'preco_minimo' => '30', 'preco_maximo' => '1.299,90', 'exige_foto' => '1']);
        self::assertSame([[(string) $this->carla]], $this->fetch('SELECT updated_by_user_id FROM account_niches WHERE installation_id = ?', [$this->a->id->value]));
        self::assertSame(
            [['air-fryers', (string) $this->ana], ['cafeteiras', (string) $this->carla], ['panelas', (string) $this->ana]],
            $this->fetch('SELECT s.slug, a.enabled_by_user_id FROM account_subniches a JOIN subniches s ON s.id = a.subniche_id WHERE a.installation_id = ? ORDER BY s.slug', [$this->a->id->value]),
        );
    }

    public function testUncheckingKeepsTheOtherSubnichesAndFiltersCanBeCleared(): void
    {
        $session = $this->signIn('ana@loja-a.test', self::PASSWORD);
        $this->save($session, 'beleza-cuidados', ['perfumes', 'maquiagem'], ['desconto_minimo' => '20', 'exige_foto' => '1']);
        $enabledAt = $this->fetch('SELECT enabled_at FROM account_subniches a JOIN subniches s ON s.id = a.subniche_id WHERE s.slug = ?', ['perfumes']);

        $this->save($session, 'beleza-cuidados', ['perfumes'], []);

        self::assertSame(['perfumes'], $this->activeSlugs($this->a));
        self::assertSame($enabledAt, $this->fetch('SELECT enabled_at FROM account_subniches a JOIN subniches s ON s.id = a.subniche_id WHERE s.slug = ?', ['perfumes']));
        self::assertSame([[null, null, null, '0']], $this->fetch('SELECT min_discount_pct, min_price, max_price, require_photo FROM account_niches WHERE installation_id = ?', [$this->a->id->value]));

        $this->save($session, 'beleza-cuidados', [], []);
        self::assertSame([], $this->activeSlugs($this->a));
        self::assertStringContainsString('0 de 5 ativos', (string) $this->httpGet('/nichos', ['sbm_session' => $session])->getBody());
    }

    public function testInvalidFiltersAreRejectedWithMessagesAndNothingChanges(): void
    {
        $session = $this->signIn('ana@loja-a.test', self::PASSWORD);
        $this->save($session, 'eletronicos', ['fones'], ['preco_maximo' => '300', 'exige_foto' => '1']);
        $before = $this->accountState();

        $cases = [
            [['desconto_minimo' => '95'], 'Use um número inteiro de 1 a 90.'],
            [['preco_minimo' => 'abc'], 'Use só números, como 30 ou 1.299,90.'],
            [['preco_minimo' => '500', 'preco_maximo' => '30'], 'O preço máximo precisa ser maior ou igual ao mínimo.'],
            [['preco_maximo' => '100.000,01'], 'Use um valor entre R$ 0,01 e R$ 100.000,00.'],
        ];
        foreach ($cases as [$form, $message]) {
            $response = $this->save($session, 'eletronicos', ['fones', 'smartwatches'], $form);
            $body = (string) $response->getBody();

            self::assertSame(422, $response->getStatusCode());
            self::assertStringContainsString($message, $body);
            self::assertStringContainsString('Nada foi salvo.', $body);
            self::assertMatchesRegularExpression('/value="smartwatches" checked/', $body, 'Mantém o que o cliente marcou.');
            self::assertSame($before, $this->accountState());
        }

        $xss = $this->save($session, 'eletronicos', ['fones'], ['preco_minimo' => '"><script>alert(1)</script>']);
        self::assertSame(422, $xss->getStatusCode());
        self::assertStringNotContainsString('<script>alert(1)', (string) $xss->getBody());
    }

    public function testSaveRequiresCsrfAndSession(): void
    {
        $session = $this->signIn('ana@loja-a.test', self::PASSWORD);
        $form = ['subnichos' => ['air-fryers'], 'exige_foto' => '1'];

        self::assertSame(400, $this->httpPost('/nichos/casa-cozinha', $form, ['sbm_session' => $session])->getStatusCode());
        self::assertSame(400, $this->httpPost('/nichos/casa-cozinha', $form + ['_csrf' => 'forjado'], ['sbm_session' => $session])->getStatusCode());
        // CSRF de outra conta não vale nesta sessão.
        $csrfB = $this->csrfFor($this->signIn('bia@loja-b.test', self::PASSWORD), '/nichos');
        self::assertSame(400, $this->httpPost('/nichos/casa-cozinha', $form + ['_csrf' => $csrfB], ['sbm_session' => $session])->getStatusCode());

        $anonymous = $this->httpPost('/nichos/casa-cozinha', $form + ['_csrf' => 'x']);
        self::assertSame(302, $anonymous->getStatusCode());
        self::assertSame('/entrar', $anonymous->getHeaderLine('Location'));
        self::assertSame(302, $this->httpGet('/nichos')->getStatusCode());

        self::assertSame(0, $this->rows('account_niches'));
        self::assertSame(0, $this->rows('account_subniches'));
    }

    public function testOptionsOutsideTheOfferedCatalogAreRejected(): void
    {
        $session = $this->signIn('ana@loja-a.test', self::PASSWORD);
        // Subnicho só com categoria REJEITADA e subnicho inativo: existem no banco, mas não são oferecidos.
        $this->db->exec("INSERT INTO subniches (niche_id, slug, name, sort) SELECT id, 'geladeiras', 'Geladeiras', 99 FROM niches WHERE slug = 'casa-cozinha'");
        $this->db->exec("INSERT INTO subniche_categories (subniche_id, site_id, ml_category_id, status, curation_version, validated_at)
            SELECT id, 'MLB', 'MLB270287', 'rejected', 'v1', NOW(3) FROM subniches WHERE slug = 'geladeiras'");
        $this->db->exec("UPDATE subniches SET active = 0 WHERE slug = 'cafeteiras'");

        $page = (string) $this->httpGet('/nichos', ['sbm_session' => $session])->getBody();
        self::assertStringNotContainsString('Geladeiras', $page);
        self::assertStringNotContainsString('Cafeteiras', $page);
        self::assertStringContainsString('0 de 4 ativos', $page);

        foreach ([
            ['casa-cozinha', ['air-fryers', 'geladeiras']],
            ['casa-cozinha', ['cafeteiras']],
            ['casa-cozinha', ['perfumes']],          // subnicho de OUTRO nicho
            ['casa-cozinha', [['air-fryers']]],      // tipo inesperado
            ['nicho-inexistente', ['air-fryers']],
        ] as [$niche, $subniches]) {
            $response = $this->httpPost('/nichos/' . $niche, ['_csrf' => $this->csrfFor($session, '/nichos'), 'subnichos' => $subniches], ['sbm_session' => $session]);
            self::assertSame(302, $response->getStatusCode());
            self::assertSame('/nichos?erro=opcao_indisponivel', $response->getHeaderLine('Location'));
        }
        self::assertSame(0, $this->rows('account_niches'));
        self::assertStringContainsString('Essa opção não está mais disponível', (string) $this->httpGet('/nichos', ['sbm_session' => $session], ['erro' => 'opcao_indisponivel'])->getBody());
        self::assertSame(404, $this->httpPost('/nichos/Casa%20e%20cozinha', ['_csrf' => 'x'], ['sbm_session' => $session])->getStatusCode());
    }

    public function testAccountsNeverSeeOrChangeEachOthersChoices(): void
    {
        $ana = $this->signIn('ana@loja-a.test', self::PASSWORD);
        $bia = $this->signIn('bia@loja-b.test', self::PASSWORD);

        $this->save($ana, 'casa-cozinha', ['air-fryers'], ['desconto_minimo' => '25', 'preco_minimo' => '99', 'exige_foto' => '1']);
        $snapshotA = $this->accountState($this->a);

        // B começa limpa: não vê nada de A.
        $pageB = (string) $this->httpGet('/nichos', ['sbm_session' => $bia])->getBody();
        self::assertStringNotContainsString('Desconto ≥ 25%', $pageB);
        self::assertStringNotContainsString('value="99"', $pageB);
        self::assertStringContainsString('0 de 5 ativos', $pageB);
        self::assertDoesNotMatchRegularExpression('/value="air-fryers" checked/', $pageB);

        // B salva o MESMO nicho com escolhas diferentes, tentando apontar para a conta A por campos extras.
        $this->httpPost('/nichos/casa-cozinha', [
            '_csrf' => $this->csrfFor($bia, '/nichos'),
            'subnichos' => ['panelas'],
            'desconto_minimo' => '5',
            'installation_id' => (string) $this->a->id->value,
            'user_id' => (string) $this->ana,
        ], ['sbm_session' => $bia]);
        self::assertSame(['panelas'], $this->activeSlugs($this->b));
        self::assertSame([['5', (string) $this->bia]], $this->fetch('SELECT min_discount_pct, updated_by_user_id FROM account_niches WHERE installation_id = ?', [$this->b->id->value]));
        self::assertSame($snapshotA, $this->accountState($this->a), 'Escrita de B não altera A.');

        // Com as duas contas no MESMO nicho, cada página mostra só as próprias escolhas.
        $pageB = (string) $this->httpGet('/nichos', ['sbm_session' => $bia])->getBody();
        self::assertMatchesRegularExpression('/value="panelas" checked/', $pageB);
        self::assertDoesNotMatchRegularExpression('/value="air-fryers" checked/', $pageB);
        self::assertStringContainsString('1 de 5 ativos', $pageB);
        self::assertStringContainsString('Desconto ≥ 5% · foto opcional', $pageB);
        $pageA = (string) $this->httpGet('/nichos', ['sbm_session' => $ana])->getBody();
        self::assertDoesNotMatchRegularExpression('/value="panelas" checked/', $pageA);
        self::assertStringContainsString('1 de 5 ativos', $pageA);

        // B desmarca tudo: só as linhas de B somem.
        $this->save($bia, 'casa-cozinha', [], []);
        self::assertSame([], $this->activeSlugs($this->b));
        self::assertSame($snapshotA, $this->accountState($this->a));

        // A continua vendo só o que é seu.
        $pageA = (string) $this->httpGet('/nichos', ['sbm_session' => $ana])->getBody();
        self::assertMatchesRegularExpression('/value="air-fryers" checked/', $pageA);
        self::assertDoesNotMatchRegularExpression('/value="panelas" checked/', $pageA);
        self::assertStringContainsString('Desconto ≥ 25% · A partir de R$ 99 · com foto', $pageA);
    }

    public function testCustomersCannotChangeTheGlobalCatalog(): void
    {
        $before = $this->catalogFingerprint();
        $ana = $this->signIn('ana@loja-a.test', self::PASSWORD);
        $csrf = $this->csrfFor($ana, '/nichos');

        $this->save($ana, 'casa-cozinha', ['air-fryers'], ['exige_foto' => '1']);
        $this->httpPost('/nichos/casa-cozinha', ['_csrf' => $csrf, 'subnichos' => ['novo-subnicho'], 'name' => 'Hackeado', 'ml_category_id' => 'MLB1'], ['sbm_session' => $ana]);
        foreach (['/nichos', '/nichos/casa-cozinha/subnichos', '/nichos/casa-cozinha/categorias'] as $path) {
            self::assertContains($this->httpPost($path, ['_csrf' => $csrf], ['sbm_session' => $ana])->getStatusCode(), [404, 405]);
        }

        self::assertSame($before, $this->catalogFingerprint());
    }

    public function testCuratedCatalogV1(): void
    {
        self::assertSame(
            [['approved', '39'], ['rejected', '26']],
            $this->fetch('SELECT status, COUNT(*) FROM subniche_categories GROUP BY status ORDER BY status'),
        );
        // Todo subnicho do catálogo v1 tem categoria aprovada (e por isso é oferecido).
        self::assertSame([], $this->fetch("SELECT s.slug FROM subniches s WHERE NOT EXISTS (
            SELECT 1 FROM subniche_categories c WHERE c.subniche_id = s.id AND c.status = 'approved')"));
        self::assertSame('16', (string) $this->db->query('SELECT COUNT(*) FROM subniches')->fetchColumn());
        // O caso "geladeira de brinquedo" da Etapa 0 está registrado como rejeitado.
        self::assertSame([['rejected']], $this->fetch("SELECT status FROM subniche_categories WHERE ml_category_id = 'MLB270287'"));
        // O banco recusa categoria aprovada sem evidência mínima.
        $this->expectException(\PDOException::class);
        $this->db->exec("INSERT INTO subniche_categories (subniche_id, site_id, ml_category_id, status, expected_domains, sample_products, sample_matching, curation_version, validated_at)
            SELECT id, 'MLB', 'MLB999', 'approved', 'MLB-X', 20, 10, 'v1', NOW(3) FROM subniches WHERE slug = 'panelas'");
    }

    /**
     * @param list<mixed>          $subniches
     * @param array<string, string> $filters
     */
    private function save(string $session, string $niche, array $subniches, array $filters): ResponseInterface
    {
        return $this->httpPost('/nichos/' . $niche, ['_csrf' => $this->csrfFor($session, '/nichos'), 'subnichos' => $subniches] + $filters, ['sbm_session' => $session]);
    }

    /** @return list<string> */
    private function activeSlugs(Installation $installation): array
    {
        return array_map(
            static fn (array $row): string => (string) $row[0],
            $this->fetch('SELECT s.slug FROM account_subniches a JOIN subniches s ON s.id = a.subniche_id WHERE a.installation_id = ? ORDER BY s.slug', [$installation->id->value]),
        );
    }

    /** @return list<list<mixed>> */
    private function accountState(?Installation $installation = null): array
    {
        $installation ??= $this->a;

        return array_merge(
            $this->fetch('SELECT * FROM account_niches WHERE installation_id = ? ORDER BY niche_id', [$installation->id->value]),
            $this->fetch('SELECT * FROM account_subniches WHERE installation_id = ? ORDER BY subniche_id', [$installation->id->value]),
        );
    }

    /** @return list<list<mixed>> */
    private function catalogFingerprint(): array
    {
        return array_merge(
            $this->fetch('SELECT * FROM niches ORDER BY id'),
            $this->fetch('SELECT * FROM subniches ORDER BY id'),
            $this->fetch('SELECT * FROM subniche_categories ORDER BY subniche_id, ml_category_id'),
        );
    }

    /**
     * @param list<mixed> $params
     *
     * @return list<list<mixed>>
     */
    private function fetch(string $sql, array $params = []): array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return array_map(
            static fn (array $row): array => array_map(static fn (mixed $v): mixed => $v === null ? null : (string) $v, $row),
            $stmt->fetchAll(\PDO::FETCH_NUM),
        );
    }

    private function rows(string $table): int
    {
        return (int) $this->db->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
    }
}
