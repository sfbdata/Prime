// @ts-check
const { test: setup, expect } = require('@playwright/test');
const path = require('path');
const fs = require('fs');
const { execSync } = require('child_process');

const STORAGE_FILE = path.join(__dirname, '../storage/auth.json');

// O banco que o localhost:8080 usa: o do app/.env.local da pasta principal (o `saas_ux` neste PC), não o `saas` fixo.
// E2E_DB_NAME no e2e/.env manda, se definido.
function bancoDoServidor() {
    if (process.env.E2E_DB_NAME) return process.env.E2E_DB_NAME;
    const url = execSync(
        `docker exec jusprime_php_dev bash -c 'cd /var/www/app && (grep -h "^DATABASE_URL=" .env.local 2>/dev/null || grep -h "^DATABASE_URL=" .env) | tail -n 1'`,
    ).toString().trim();
    const nome = url.replace(/["']/g, '').split('?')[0].split('/').pop();
    if (!nome) throw new Error('Não achei o DATABASE_URL da pasta principal; defina E2E_DB_NAME no e2e/.env');
    return nome;
}

// Gênero preenchido no usuário de testes, para a janela "Como devemos tratar você?" (spec genero-no-cadastro,
// regra 3) não cobrir os cliques dos specs. A migration que cria o usuário (Version20260508170000) já foi aplicada
// nos bancos de dev; por isso o acerto mora aqui, idempotente. Cria o perfil se faltar.
function preencherGeneroDoUsuarioE2E() {
    const sql = `
        INSERT INTO user_profiles (user_id, criado_em, genero)
        SELECT u.id, NOW(), 'masculino' FROM "user" u WHERE u.email = 'e2e@jusprime.local'
        ON CONFLICT (user_id) DO UPDATE SET genero = 'masculino'
        WHERE user_profiles.genero IS NULL OR user_profiles.genero = 'nao_informado'`;
    execSync(
        `docker exec -i jusprime_db_dev psql -v ON_ERROR_STOP=1 -U symfony -d ${JSON.stringify(bancoDoServidor())} -q`,
        { input: sql, stdio: ['pipe', 'pipe', 'pipe'] },
    );
}

// Aceite dos Termos de Uso da versão vigente, para o gate de Termos (TermoAceiteListener) não levar o usuário de
// testes à tela de aceite. A versão sai de TermoVigente::VERSAO no container, então a troca da constante não
// derruba o smoke. Idempotente (índice único user+versao+origem). Não mexe na tela nem no texto dos Termos.
function aceitarTermosDoUsuarioE2E() {
    const fonte = execSync('docker exec jusprime_php_dev cat /var/www/app/src/Termo/TermoVigente.php').toString();
    const versao = (fonte.match(/VERSAO = '([^']+)'/) ?? [])[1];
    if (!versao || !/^[\w.-]+$/.test(versao)) throw new Error('Não achei TermoVigente::VERSAO em app/src/Termo/TermoVigente.php');
    const sql = `
        INSERT INTO aceite_termo (user_id, versao, aceito_em, ip, user_agent, origem)
        SELECT u.id, '${versao}', NOW(), '127.0.0.1', 'e2e', 'cadastro' FROM "user" u WHERE u.email = 'e2e@jusprime.local'
        ON CONFLICT (user_id, versao, origem) DO NOTHING`;
    execSync(
        `docker exec -i jusprime_db_dev psql -v ON_ERROR_STOP=1 -U symfony -d ${JSON.stringify(bancoDoServidor())} -q`,
        { input: sql, stdio: ['pipe', 'pipe', 'pipe'] },
    );
}

setup('autenticar usuário de teste', async ({ page }) => {
    preencherGeneroDoUsuarioE2E();
    aceitarTermosDoUsuarioE2E();

    await page.goto('/login');

    // Verifica se há mensagem de rate limit antes de tentar
    const rateLimitMsg = page.locator('text=Muitas tentativas');
    const temRateLimit = await rateLimitMsg.isVisible().catch(() => false);
    if (temRateLimit) {
        throw new Error('Rate limiter ativo — limpe o cache antes de rodar: docker exec jusprime_php_dev bash -c "cd /var/www/app && php bin/console cache:pool:clear cache.rate_limiter"');
    }

    await page.fill('input[name="email"], input[name="_username"]', 'e2e@jusprime.local');
    await page.fill('input[name="password"], input[name="_password"]',
        process.env.E2E_USER_PASSWORD ?? 'e2e_local_placeholder');
    await page.click('button[type="submit"]');

    // Aguarda redirecionar para fora do login (aumentado para 20s)
    await page.waitForURL(url => !url.pathname.includes('/login'), { timeout: 20000 });

    await page.context().storageState({ path: STORAGE_FILE });
});
