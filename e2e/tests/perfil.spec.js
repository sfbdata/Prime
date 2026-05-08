// @ts-check
// ATENÇÃO: Estes testes escrevem em user_profiles (nome_completo, status, foto_url)
// do usuário de testes (e2e@jusprime.local). Para restaurar o estado original após
// rodar a suite completa: bash e2e/scripts/restore-e2e-user.sh
const { test, expect } = require('@playwright/test');
const path = require('path');

// ─────────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────────

/** Navega para /perfil via navbar (avatar → "Meu Perfil") */
async function navegarParaPerfilPelaNavbar(page) {
    await page.goto('/');
    await page.waitForSelector('.navtop-avatar-btn', { state: 'visible', timeout: 15000 });
    await page.click('.navtop-avatar-btn');

    const linkPerfil = page.locator('.usuario-dropdown a[href*="perfil"]');
    await expect(linkPerfil).toBeVisible({ timeout: 5000 });
    await linkPerfil.click();

    await page.waitForURL('**/perfil', { timeout: 15000 });
    await page.waitForSelector('.perfil-card', { state: 'visible', timeout: 15000 });
}

/** Vai direto para /perfil, garantindo que nenhum modal esteja aberto */
async function irParaPerfil(page) {
    await page.goto('/perfil');
    await page.waitForSelector('.perfil-card', { state: 'visible', timeout: 15000 });
    // Garante que nenhum modal Bootstrap bloqueie interações (incluindo modais sem .show)
    await page.evaluate(() => {
        document.querySelectorAll('.modal').forEach(m => {
            m.classList.remove('show');
            m.style.display = 'none';
        });
        document.querySelectorAll('.modal-backdrop').forEach(b => b.remove());
        document.body.classList.remove('modal-open');
        document.body.style.overflow = '';
        document.body.style.paddingRight = '';
    });
}

/** Abre o modal de dados pessoais e aguarda estar interagível */
async function abrirModalDadosPessoais(page) {
    await page.click('button[data-bs-target="#modalDadosPessoais"]');
    await page.waitForSelector('#modalDadosPessoais.show', { state: 'visible', timeout: 8000 });
    // O Bootstrap mantém a classe fade — aguardamos o campo ser clicável
    await page.locator('#dados_pessoais_nomeCompleto').waitFor({ state: 'visible', timeout: 5000 });
}

/** Abre o modal de foto e aguarda a animação terminar */
async function abrirModalFoto(page) {
    await page.click('button[data-bs-target="#modalFotoVer"]');
    await page.waitForSelector('#modalFotoVer.show', { state: 'visible', timeout: 8000 });
    await page.locator('#modalFotoVer .modal-footer').waitFor({ state: 'visible', timeout: 5000 });
}

/**
 * Abre o modal de foto, faz upload de uma imagem e aguarda o cropper abrir.
 * O input#inputFotoNova tem atributo `hidden` e o browser tem o id duplicado
 * no campo CPF. O setInputFiles funciona com inputs hidden e aciona o FileReader
 * que lê a imagem. O modal de visualização precisa estar fechado antes do cropper abrir.
 */
async function uploadFotoEAbrirCropper(page) {
    await abrirModalFoto(page);

    const imagemFixture = path.join(__dirname, '../fixtures/foto-teste.jpg');
    await page.locator('#inputFotoNova').setInputFiles(imagemFixture);

    // setInputFiles em input[hidden] não dispara o evento 'change' automaticamente
    await page.evaluate(() => {
        const input = document.getElementById('inputFotoNova');
        if (input && input.files?.length) {
            input.dispatchEvent(new Event('change', { bubbles: true }));
        }
    });

    // O JS fecha modalFotoVer e abre modalFotoEditar em sequência
    await page.waitForSelector('#modalFotoEditar.show', { state: 'visible', timeout: 20000 });
    await page.locator('#cropperWrap').waitFor({ state: 'visible', timeout: 8000 });
}

// ─────────────────────────────────────────────────────────────────
// Suite: acesso pela navbar
// ─────────────────────────────────────────────────────────────────

test.describe('Perfil — acesso pela navbar superior', () => {
    test('avatar do usuário está visível na navbar', async ({ page }) => {
        await page.goto('/');
        await expect(page.locator('.navtop-avatar-btn')).toBeVisible({ timeout: 20000 });
    });

    test('clicar no avatar abre dropdown com "Meu Perfil"', async ({ page }) => {
        await page.goto('/');
        await page.waitForSelector('.navtop-avatar-btn', { state: 'visible', timeout: 20000 });
        await page.click('.navtop-avatar-btn');

        const linkPerfil = page.locator('.usuario-dropdown a[href*="perfil"]');
        await expect(linkPerfil).toBeVisible({ timeout: 5000 });
        await expect(linkPerfil).toContainText('Meu Perfil');
    });

    test('clicar em "Meu Perfil" navega para /perfil', async ({ page }) => {
        await navegarParaPerfilPelaNavbar(page);
        await expect(page).toHaveURL(/\/perfil/);
    });

    test('página de perfil exibe o card principal', async ({ page }) => {
        await navegarParaPerfilPelaNavbar(page);
        await expect(page.locator('.perfil-card')).toBeVisible();
        await expect(page.locator('.perfil-capa')).toBeVisible();
        await expect(page.locator('.perfil-nome')).toBeVisible();
    });

    test('nome do usuário exibido no perfil não está vazio', async ({ page }) => {
        await navegarParaPerfilPelaNavbar(page);
        const nomePerfil = await page.locator('#perfilNomeExibido').textContent();
        expect(nomePerfil?.trim().length).toBeGreaterThan(0);
    });
});

// ─────────────────────────────────────────────────────────────────
// Suite: dados pessoais
// ─────────────────────────────────────────────────────────────────

test.describe('Perfil — editar dados pessoais', () => {
    test.beforeEach(async ({ page }) => {
        await irParaPerfil(page);
    });

    test('botão "Editar informações" está visível', async ({ page }) => {
        await expect(page.locator('button[data-bs-target="#modalDadosPessoais"]')).toBeVisible();
    });

    test('clicar em "Editar informações" abre o modal', async ({ page }) => {
        await abrirModalDadosPessoais(page);
        await expect(page.locator('#modalDadosPessoais .modal-title')).toContainText('Informações pessoais');
    });

    test('modal contém os campos: Nome completo, CPF, Data de nascimento, CTPS, Série', async ({ page }) => {
        await abrirModalDadosPessoais(page);

        // IDs gerados pelo Symfony: dados_pessoais_<campo>
        // O campo CPF tem dois atributos id no HTML (bug do Symfony + attr); o browser usa o primeiro
        await expect(page.locator('#dados_pessoais_nomeCompleto')).toBeVisible();
        await expect(page.locator('#dados_pessoais_cpf')).toBeVisible();
        await expect(page.locator('#dados_pessoais_dataNascimento')).toBeVisible();
        await expect(page.locator('#dados_pessoais_ctps')).toBeVisible();
        await expect(page.locator('#dados_pessoais_serie')).toBeVisible();
    });

    test('fechar modal pelo botão Cancelar fecha o modal', async ({ page }) => {
        await abrirModalDadosPessoais(page);
        await page.click('#modalDadosPessoais button[data-bs-dismiss="modal"]');
        await page.waitForSelector('#modalDadosPessoais', { state: 'hidden', timeout: 5000 });
        await expect(page.locator('#modalDadosPessoais')).not.toHaveClass(/show/);
    });

    test('salvar dados pessoais exibe mensagem de sucesso', async ({ page }) => {
        await abrirModalDadosPessoais(page);

        await page.locator('#dados_pessoais_nomeCompleto').fill('Advogado Teste E2E');
        await page.click('#formDadosPessoais button[type="submit"]');

        await page.waitForURL('**/perfil', { timeout: 15000 });
        await expect(page.locator('.alert-success')).toBeVisible({ timeout: 5000 });
    });

    test('campo CPF aceita entrada numérica no formato correto', async ({ page }) => {
        await abrirModalDadosPessoais(page);

        // O campo CPF aceita entrada via teclado com máximo de 14 chars
        // Nota: o JS tenta aplicar máscara via getElementById('campoCpf'),
        // mas o browser usa o primeiro id do elemento (dados_pessoais_cpf),
        // então a máscara automática não está ativa — o campo aceita o valor sem formatar.
        const campoCpf = page.locator('#dados_pessoais_cpf');
        await campoCpf.fill('123.456.789-01');

        await expect(campoCpf).toHaveValue('123.456.789-01');
    });

    test('salvar com nome vazio mantém o modal aberto (reaberto por flash error)', async ({ page }) => {
        await abrirModalDadosPessoais(page);

        await page.locator('#dados_pessoais_nomeCompleto').fill('');
        await page.click('#formDadosPessoais button[type="submit"]');

        await page.waitForURL('**/perfil', { timeout: 15000 });
        await page.waitForSelector('#modalDadosPessoais.show', { timeout: 8000 });
        await expect(page.locator('#modalDadosPessoais')).toHaveClass(/show/);
    });

    test('dados salvos aparecem na tela de perfil após salvar', async ({ page }) => {
        await abrirModalDadosPessoais(page);

        const novoNome = 'Nome Atualizado E2E';
        await page.locator('#dados_pessoais_nomeCompleto').fill(novoNome);
        await page.click('#formDadosPessoais button[type="submit"]');

        await page.waitForURL('**/perfil', { timeout: 15000 });
        await expect(page.locator('#perfilNomeExibido')).toContainText(novoNome);
    });
});

// ─────────────────────────────────────────────────────────────────
// Suite: status
// ─────────────────────────────────────────────────────────────────

test.describe('Perfil — campo de status', () => {
    test.beforeEach(async ({ page }) => {
        await irParaPerfil(page);
    });

    test('campo de status está visível na página', async ({ page }) => {
        await expect(page.locator('#statusInput')).toBeVisible();
    });

    test('botões Salvar e Cancelar estão ocultos inicialmente', async ({ page }) => {
        await expect(page.locator('#btnSalvarStatus')).toBeHidden();
        await expect(page.locator('#btnCancelarStatus')).toBeHidden();
    });

    test('focar no campo de status exibe os botões Salvar e Cancelar', async ({ page }) => {
        await page.click('#statusInput');
        await expect(page.locator('#btnSalvarStatus')).toBeVisible({ timeout: 3000 });
        await expect(page.locator('#btnCancelarStatus')).toBeVisible({ timeout: 3000 });
    });

    test('digitar no campo exibe os botões', async ({ page }) => {
        await page.locator('#statusInput').fill('Trabalhando no e2e');
        await expect(page.locator('#btnSalvarStatus')).toBeVisible({ timeout: 3000 });
    });

    test('clicar em Cancelar restaura o valor original e oculta os botões', async ({ page }) => {
        const valorOriginal = await page.locator('#statusInput').inputValue();

        await page.click('#statusInput');
        await expect(page.locator('#btnCancelarStatus')).toBeVisible({ timeout: 3000 });

        await page.locator('#statusInput').fill('Texto temporário');
        await page.click('#btnCancelarStatus');

        await expect(page.locator('#statusInput')).toHaveValue(valorOriginal);
        await expect(page.locator('#btnSalvarStatus')).toBeHidden();
        await expect(page.locator('#btnCancelarStatus')).toBeHidden();
    });

    test('salvar status com novo texto funciona (resposta ok)', async ({ page }) => {
        const novoStatus = `Status E2E ${Date.now()}`;
        await page.locator('#statusInput').fill(novoStatus);
        await page.click('#btnSalvarStatus');

        await expect(page.locator('#btnSalvarStatus')).toBeHidden({ timeout: 8000 });
        await expect(page.locator('#statusInput')).toHaveValue(novoStatus);
    });

    test('status salvo persiste após recarregar a página', async ({ page }) => {
        const novoStatus = `Persistência E2E ${Date.now()}`;
        await page.locator('#statusInput').fill(novoStatus);
        await page.click('#btnSalvarStatus');
        await expect(page.locator('#btnSalvarStatus')).toBeHidden({ timeout: 8000 });

        await page.reload();
        await page.waitForSelector('#statusInput', { state: 'visible', timeout: 10000 });
        await expect(page.locator('#statusInput')).toHaveValue(novoStatus);
    });

    test('salvar status vazio é permitido (limpar status)', async ({ page }) => {
        await page.locator('#statusInput').fill('');
        await page.click('#btnSalvarStatus');
        await expect(page.locator('#btnSalvarStatus')).toBeHidden({ timeout: 8000 });
        await expect(page.locator('#statusInput')).toHaveValue('');
    });

    test('campo de status respeita o limite de 255 caracteres', async ({ page }) => {
        const texto256 = 'a'.repeat(256);
        await page.locator('#statusInput').fill(texto256);
        const valor = await page.locator('#statusInput').inputValue();
        expect(valor.length).toBeLessThanOrEqual(255);
    });
});

// ─────────────────────────────────────────────────────────────────
// Suite: foto de perfil
// ─────────────────────────────────────────────────────────────────

test.describe('Perfil — foto de perfil', () => {
    test.beforeEach(async ({ page }) => {
        await irParaPerfil(page);
    });

    test('botão de foto está visível na capa', async ({ page }) => {
        await expect(page.locator('.perfil-foto-btn')).toBeVisible();
    });

    test('clicar na foto abre o modal de visualização', async ({ page }) => {
        await abrirModalFoto(page);
        await expect(page.locator('#modalFotoVer .modal-title')).toContainText('Foto de perfil');
    });

    test('modal de foto exibe botão "Alterar imagem"', async ({ page }) => {
        await abrirModalFoto(page);
        await expect(page.locator('#modalFotoVer label:has(#inputFotoNova)')).toBeVisible({ timeout: 5000 });
    });

    test('enviar uma nova foto abre o modal do cropper', async ({ page }) => {
        await uploadFotoEAbrirCropper(page);
        await expect(page.locator('#cropperWrap')).toBeVisible();
    });

    test('modal do cropper exibe sliders de zoom e rotação', async ({ page }) => {
        await uploadFotoEAbrirCropper(page);
        await expect(page.locator('#sliderZoom')).toBeVisible();
        await expect(page.locator('#sliderRotacao')).toBeVisible();
    });

    test('botão Cancelar no cropper fecha o modal do cropper', async ({ page }) => {
        await uploadFotoEAbrirCropper(page);
        await page.click('#btnCancelarCropper');
        await page.waitForSelector('#modalFotoEditar', { state: 'hidden', timeout: 8000 });
        await expect(page.locator('#modalFotoEditar')).not.toHaveClass(/show/);
    });

    test('botão X (fechar) no cropper fecha o modal do cropper', async ({ page }) => {
        await uploadFotoEAbrirCropper(page);
        await page.click('#btnFecharCropper');
        await page.waitForSelector('#modalFotoEditar', { state: 'hidden', timeout: 8000 });
        await expect(page.locator('#modalFotoEditar')).not.toHaveClass(/show/);
    });

    test('confirmar foto no cropper envia e exibe a nova foto na página', async ({ page }) => {
        await uploadFotoEAbrirCropper(page);

        // Aguarda o cropper.js inicializar completamente
        await page.waitForTimeout(2000);

        await page.click('#btnConfirmarFoto');

        await page.waitForSelector('#modalFotoEditar', { state: 'hidden', timeout: 20000 });
        await expect(page.locator('.perfil-foto-btn img')).toBeVisible({ timeout: 8000 });
    });

    test('após enviar foto, o botão "Editar imagem" aparece no modal de foto', async ({ page }) => {
        await abrirModalFoto(page);
        const btnEditar = page.locator('#btnEditarFotoExistente');
        const visivel = await btnEditar.isVisible();

        if (!visivel) {
            const imagemFixture = path.join(__dirname, '../fixtures/foto-teste.jpg');
            await page.locator('#inputFotoNova').setInputFiles(imagemFixture);
            await page.evaluate(() => {
                const input = document.getElementById('inputFotoNova');
                if (input && input.files?.length) {
                    input.dispatchEvent(new Event('change', { bubbles: true }));
                }
            });
            await page.waitForSelector('#modalFotoEditar.show', { state: 'visible', timeout: 20000 });
            await page.waitForTimeout(2000);
            await page.click('#btnConfirmarFoto');
            await page.waitForSelector('#modalFotoEditar', { state: 'hidden', timeout: 20000 });

            await abrirModalFoto(page);
        }

        await expect(btnEditar).toBeVisible();
    });

    test('clicar em "Editar imagem" (foto existente) abre o cropper diretamente', async ({ page }) => {
        await abrirModalFoto(page);

        const btnEditar = page.locator('#btnEditarFotoExistente');
        const visivel = await btnEditar.isVisible();
        test.skip(!visivel, 'Usuário não possui foto — pulando teste de edição de foto existente');

        await btnEditar.click();
        await page.waitForSelector('#modalFotoEditar.show', { state: 'visible', timeout: 10000 });
        await expect(page.locator('#cropperWrap')).toBeVisible();
    });
});
