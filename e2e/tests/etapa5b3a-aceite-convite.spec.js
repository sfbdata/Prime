// @ts-check
// Fase 5b.3a — Aceite de Convites
//
// 13 cenários cobrindo todos os fluxos do convidado:
//   1. Token inválido → tela de erro "não encontrado"
//   2. Token expirado → tela de erro "expirado"
//   3. Token já usado → tela de erro "já utilizado"
//   4. Plataforma, email sem conta → formulário com OAB → cria conta → login
//   5. Escritório, email sem conta → formulário sem OAB → cria conta → login
//   6. Escritório, email com conta, não logado → redirect para login
//   7. Escritório, logado, email não bate → tela "não pertence"
//   8. Escritório, logado, aceitar → flash sucesso + user_tenant criado
//   9. Escritório, logado, recusar → flash info + invitation rejected
//  10. Meus convites → lista convites pendentes do e2e user
//  11. Redirect legado /register/confirm/{token} → 302 para /convite/{token}
//  12. Redirect legado /invite → 302 para /
//  13. Rate limit → 21 requests de mesmo IP → HTTP 429

const { test, expect } = require('@playwright/test');
const { execSync } = require('child_process');

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

const runSQL = (query) =>
    execSync(
        `docker exec jusprime_db_dev psql -U symfony -d saas -t -A -c ${JSON.stringify(query.replace(/\s+/g, ' ').trim())}`,
        { stdio: ['pipe', 'pipe', 'pipe'] }
    ).toString().trim();

// Tokens de teste (≤ 64 chars, prefixo 'e2e3a' para limpeza fácil)
const TK = {
    expirado:       'e2e3a0expirado0000000000000000000000000000000000000000000000001',
    usado:          'e2e3a0usado000000000000000000000000000000000000000000000000002',
    plataforma:     'e2e3a0plataforma000000000000000000000000000000000000000000003',
    escSemConta:    'e2e3a0escritorio-sem-conta-0000000000000000000000000000000004',
    escComConta:    'e2e3a0escritorio-com-conta-0000000000000000000000000000000005',
    naoPertenco:    'e2e3a0nao-pertence-000000000000000000000000000000000000000006',
    aceitar:        'e2e3a0aceitar-000000000000000000000000000000000000000000007',
    recusar:        'e2e3a0recusar-000000000000000000000000000000000000000000008',
    meusC1:         'e2e3a0meus-convites-1-000000000000000000000000000000000000009',
    meusC2:         'e2e3a0meus-convites-2-000000000000000000000000000000000000010',
};

const EMAIL_PLATAFORMA = 'e2e3a-plataforma@teste.local';
const EMAIL_ESCRITORIO = 'e2e3a-escritorio@teste.local';
const EMAIL_OUTRO      = 'e2e3a-outro@teste.local';
const EMAIL_E2E        = 'e2e@jusprime.local';

// Tenant dedicado para testes de aceite (e2e user NÃO é membro)
const TENANT_TESTE_NOME = 'Tenant E2E 5b3a';

// IDs capturados no beforeAll
let tenantPrincipalId = '';
let tenantTesteId = '';

// ---------------------------------------------------------------------------
// Setup / Teardown
// ---------------------------------------------------------------------------

test.beforeAll(async () => {
    // Limpeza preventiva de runs anteriores
    runSQL(`DELETE FROM user_tenant WHERE tenant_id IN (SELECT id FROM tenant WHERE name = '${TENANT_TESTE_NOME}')`);
    runSQL(`DELETE FROM invitation WHERE token LIKE 'e2e3a%'`);
    runSQL(`DELETE FROM "user" WHERE email IN ('${EMAIL_PLATAFORMA}','${EMAIL_ESCRITORIO}','${EMAIL_OUTRO}')`);
    runSQL(`DELETE FROM tenant WHERE name = '${TENANT_TESTE_NOME}'`);

    // EMAIL_OUTRO precisa existir para que o cenário 7 (não pertence) funcione:
    // a conta existe mas pertence a outro email, então o logado (e2e@jusprime.local) vê "não pertence"
    runSQL(`INSERT INTO "user" (email, full_name, roles, is_active, created_at) VALUES ('${EMAIL_OUTRO}', 'Outro Usuário Teste', '["ROLE_USER"]', true, NOW())`);

    tenantPrincipalId = runSQL(`SELECT id FROM tenant LIMIT 1`);

    // Sincronizar sequência do id de invitation (evita conflito com registro legado id=1)
    runSQL(`SELECT setval(pg_get_serial_sequence('invitation', 'id'), GREATEST((SELECT MAX(id) FROM invitation), 1))`);


    runSQL(`INSERT INTO tenant (name, is_active, created_at) VALUES ('${TENANT_TESTE_NOME}', true, NOW())`);
    tenantTesteId = runSQL(`SELECT id FROM tenant WHERE name = '${TENANT_TESTE_NOME}'`);

    const fut = `NOW() + INTERVAL '7 days'`;
    const pas = `NOW() - INTERVAL '1 day'`;

    // Token expirado (expires_at no passado)
    runSQL(`INSERT INTO invitation (email, token, type, status, expires_at, created_at, tenant_id)
            VALUES ('x@x.com', '${TK.expirado}', 'escritorio', 'pending', ${pas}, NOW(), ${tenantPrincipalId})`);

    // Token já usado
    runSQL(`INSERT INTO invitation (email, token, type, status, expires_at, created_at, tenant_id)
            VALUES ('x@x.com', '${TK.usado}', 'escritorio', 'accepted', ${fut}, NOW(), ${tenantPrincipalId})`);

    // Plataforma — email sem conta
    runSQL(`INSERT INTO invitation (email, token, type, status, expires_at, created_at)
            VALUES ('${EMAIL_PLATAFORMA}', '${TK.plataforma}', 'plataforma', 'pending', ${fut}, NOW())`);

    // Escritório — email sem conta
    runSQL(`INSERT INTO invitation (email, token, type, status, expires_at, created_at, tenant_id)
            VALUES ('${EMAIL_ESCRITORIO}', '${TK.escSemConta}', 'escritorio', 'pending', ${fut}, NOW(), ${tenantPrincipalId})`);

    // Escritório — e2e user existe, verifica redirect para login
    runSQL(`INSERT INTO invitation (email, token, type, status, expires_at, created_at, tenant_id)
            VALUES ('${EMAIL_E2E}', '${TK.escComConta}', 'escritorio', 'pending', ${fut}, NOW(), ${tenantPrincipalId})`);

    // Não pertence (email é EMAIL_OUTRO, e2e vai logado mas com outro email)
    runSQL(`INSERT INTO invitation (email, token, type, status, expires_at, created_at, tenant_id)
            VALUES ('${EMAIL_OUTRO}', '${TK.naoPertenco}', 'escritorio', 'pending', ${fut}, NOW(), ${tenantPrincipalId})`);

    // Aceitar — e2e user, tenant onde NÃO é membro
    runSQL(`INSERT INTO invitation (email, token, type, status, expires_at, created_at, tenant_id)
            VALUES ('${EMAIL_E2E}', '${TK.aceitar}', 'escritorio', 'pending', ${fut}, NOW(), ${tenantTesteId})`);

    // Recusar — e2e user, tenant teste
    runSQL(`INSERT INTO invitation (email, token, type, status, expires_at, created_at, tenant_id)
            VALUES ('${EMAIL_E2E}', '${TK.recusar}', 'escritorio', 'pending', ${fut}, NOW(), ${tenantTesteId})`);

    // Meus convites — 2 pendentes para e2e user
    runSQL(`INSERT INTO invitation (email, token, type, status, expires_at, created_at, tenant_id)
            VALUES ('${EMAIL_E2E}', '${TK.meusC1}', 'escritorio', 'pending', ${fut}, NOW(), ${tenantPrincipalId})`);
    runSQL(`INSERT INTO invitation (email, token, type, status, expires_at, created_at, tenant_id)
            VALUES ('${EMAIL_E2E}', '${TK.meusC2}', 'escritorio', 'pending', ${fut}, NOW(), ${tenantTesteId})`);
});

test.afterAll(async () => {
    runSQL(`DELETE FROM user_tenant WHERE tenant_id IN (SELECT id FROM tenant WHERE name = '${TENANT_TESTE_NOME}')`);
    runSQL(`DELETE FROM invitation WHERE token LIKE 'e2e3a%'`);
    runSQL(`DELETE FROM "user" WHERE email IN ('${EMAIL_PLATAFORMA}','${EMAIL_ESCRITORIO}','${EMAIL_OUTRO}')`);
    runSQL(`DELETE FROM tenant WHERE name = '${TENANT_TESTE_NOME}'`);
    execSync(
        `docker exec jusprime_php_dev bash -c 'cd app && php bin/console cache:pool:clear cache.rate_limiter'`,
        { stdio: 'pipe' }
    );
});

// ---------------------------------------------------------------------------
// Grupo A — Sem autenticação
// ---------------------------------------------------------------------------

test.describe('Etapa 5b.3a — Tokens inválidos/expirados/usados', () => {
    test.use({ storageState: { cookies: [], origins: [] } });

    test('1. Token inexistente → tela "não encontrado"', async ({ page }) => {
        await page.goto('/convite/token-que-definitivamente-nao-existe-xyzxyz');
        await expect(page.locator('h1')).toContainText('não encontrado');
    });

    test('2. Token expirado → tela "expirado"', async ({ page }) => {
        await page.goto(`/convite/${TK.expirado}`);
        await expect(page.locator('h1')).toContainText('expirado');
    });

    test('3. Token já usado → tela "já utilizado"', async ({ page }) => {
        await page.goto(`/convite/${TK.usado}`);
        await expect(page.locator('h1')).toContainText('utilizado');
    });
});

// ---------------------------------------------------------------------------
// Grupo B — Criação de conta (sem autenticação)
// ---------------------------------------------------------------------------

test.describe('Etapa 5b.3a — Plataforma: criar conta com OAB', () => {
    test.use({ storageState: { cookies: [], origins: [] } });

    test('4. Convite plataforma → formulário com campos OAB → cria conta → redireciona para login', async ({ page }) => {
        await page.goto(`/convite/${TK.plataforma}`);

        // Formulário visível com campo de OAB
        await expect(page.locator('input[name="full_name"]')).toBeVisible();
        await expect(page.locator('input[name="oab_numero"]')).toBeVisible();
        await expect(page.locator('input[name="oab_uf"]')).toBeVisible();

        await page.fill('input[name="full_name"]', 'Advogado Teste Plataforma');
        await page.fill('input[name="senha"]', 'Senha@1234567');
        await page.fill('input[name="oab_numero"]', '987654');
        await page.fill('input[name="oab_uf"]', 'RJ');
        await page.click('button[type="submit"]');

        await expect(page).toHaveURL(/\/login/);
        await expect(page.locator('body')).toContainText('Conta criada com sucesso');
    });
});

test.describe('Etapa 5b.3a — Escritório: criar conta sem OAB', () => {
    test.use({ storageState: { cookies: [], origins: [] } });

    test('5. Convite escritório, email sem conta → formulário sem OAB → cria conta → redireciona para login', async ({ page }) => {
        await page.goto(`/convite/${TK.escSemConta}`);

        // Formulário sem campo OAB
        await expect(page.locator('input[name="full_name"]')).toBeVisible();
        await expect(page.locator('input[name="oab_numero"]')).not.toBeVisible();

        await page.fill('input[name="full_name"]', 'Colaborador Teste Escritório');
        await page.fill('input[name="senha"]', 'Senha@1234567');
        await page.click('button[type="submit"]');

        await expect(page).toHaveURL(/\/login/);
        await expect(page.locator('body')).toContainText('Conta criada com sucesso');
    });
});

// ---------------------------------------------------------------------------
// Grupo C — Redirect para login (sem autenticação)
// ---------------------------------------------------------------------------

test.describe('Etapa 5b.3a — Escritório com conta: redirect para login', () => {
    test.use({ storageState: { cookies: [], origins: [] } });

    test('6. Convite para email com conta existente + não logado → redirect para /login', async ({ page }) => {
        await page.goto(`/convite/${TK.escComConta}`);
        await expect(page).toHaveURL(/\/login/);
    });
});

// ---------------------------------------------------------------------------
// Grupo D — Logado como e2e@jusprime.local
// ---------------------------------------------------------------------------

test.describe('Etapa 5b.3a — Logado: convite de outra conta', () => {
    // usa storageState padrão (e2e@jusprime.local)

    test('7. Convite para outro email, logado → tela "não pertence"', async ({ page }) => {
        await page.goto(`/convite/${TK.naoPertenco}`);
        await expect(page.locator('h1')).toContainText('outra conta');
        await expect(page.locator('body')).toContainText(EMAIL_OUTRO);
    });
});

test.describe('Etapa 5b.3a — Logado: aceitar convite', () => {
    test('8. Aceitar convite → flash "Bem-vindo" + redirect para homepage', async ({ page }) => {
        await page.goto(`/convite/${TK.aceitar}`);

        await expect(page.locator('button[type="submit"]').first()).toBeVisible();

        // Clica no botão de aceitar (primeiro form)
        await page.locator('form[action*="/aceitar"] button[type="submit"]').click();

        await expect(page).not.toHaveURL(/\/convite\//);
        await expect(page.locator('body')).toContainText('Bem-vindo');

        // Confirma que a invitation foi marcada como aceita no banco
        const status = runSQL(`SELECT status FROM invitation WHERE token = '${TK.aceitar}'`);
        expect(status).toBe('accepted');
    });
});

test.describe('Etapa 5b.3a — Logado: recusar convite', () => {
    test('9. Recusar convite → flash "recusado" + redirect', async ({ page }) => {
        await page.goto(`/convite/${TK.recusar}`);

        await page.locator('form[action*="/recusar"] button[type="submit"]').click();

        await expect(page).not.toHaveURL(/\/convite\//);
        await expect(page.locator('body')).toContainText('recusado');

        const status = runSQL(`SELECT status FROM invitation WHERE token = '${TK.recusar}'`);
        expect(status).toBe('rejected');
    });
});

test.describe('Etapa 5b.3a — Meus convites', () => {
    test('10. GET /convites → lista convites pendentes para o e2e user', async ({ page }) => {
        await page.goto('/convites');

        await expect(page).toHaveURL(/\/convites/);
        await expect(page.locator('body')).not.toContainText('Internal Server Error');

        // Deve haver ao menos 1 convite pendente na lista (meusC1 e meusC2 foram inseridos)
        const linhas = page.locator('tbody tr');
        const count = await linhas.count();
        expect(count).toBeGreaterThanOrEqual(1);
        // Verifica que há botão "Ver convite" apontando para uma rota de convite
        await expect(page.locator('a[href*="/convite/"]').first()).toBeVisible();
    });
});

// ---------------------------------------------------------------------------
// Grupo E — Redirects legados (sem autenticação)
// ---------------------------------------------------------------------------

test.describe('Etapa 5b.3a — Redirects legados', () => {
    test.use({ storageState: { cookies: [], origins: [] } });

    test('11. GET /register/confirm/{token} → 302 para /convite/{token}', async ({ page }) => {
        const token = 'algum-token-legado';
        const response = await page.goto(`/register/confirm/${token}`, { waitUntil: 'commit' });

        // Playwright segue o redirect, então verificamos a URL final
        await expect(page).toHaveURL(new RegExp(`/convite/${token}`));
    });

    test('12. GET /invite → 302 para /', async ({ page }) => {
        await page.goto('/invite', { waitUntil: 'networkidle' });
        // Playwright segue o redirect — deve chegar no login (protegido por ROLE_USER, ou homepage)
        await expect(page).not.toHaveURL('/invite');
    });
});

// ---------------------------------------------------------------------------
// Grupo F — Rate limit (sem autenticação, cache limpo antes)
// ---------------------------------------------------------------------------

test.describe('Etapa 5b.3a — Rate limit', () => {
    test.use({ storageState: { cookies: [], origins: [] } });

    test.beforeAll(() => {
        execSync(
            `docker exec jusprime_php_dev bash -c 'cd app && php bin/console cache:pool:clear cache.rate_limiter'`,
            { stdio: 'pipe' }
        );
    });

    test('13. 21ª requisição para /convite/{token} retorna 429', async ({ page }) => {
        const token = 'token-rate-limit-test-xxxxxxxxxxx';

        // 20 requisições dentro do limite
        for (let i = 0; i < 20; i++) {
            const r = await page.request.get(`/convite/${token}`);
            expect(r.status()).not.toBe(429);
        }

        // 21ª deve ser bloqueada
        const limitada = await page.request.get(`/convite/${token}`);
        expect(limitada.status()).toBe(429);
    });
});
