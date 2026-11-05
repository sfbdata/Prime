// Senhas dos testes E2E vêm SÓ de variável de ambiente (e2e/.env, lido pelo playwright.config.js).
// Auditoria 4.07: nenhuma senha fica escrita no repositório, nem como valor padrão. Sem a variável,
// o teste para com uma mensagem que diz qual variável definir.
function senhaDoAmbiente(nome) {
    const valor = process.env[nome];
    if (valor === undefined || valor === '') {
        throw new Error(`Defina ${nome} no e2e/.env (veja o e2e/.env.example). A senha não tem valor padrão no repositório.`);
    }
    return valor;
}

module.exports = { senhaDoAmbiente };
