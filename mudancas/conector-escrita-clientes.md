---
tipo: novidade
---
## Changelog
- Cliente: a leitura do cliente e a conferência de CPF ou CNPJ saíram dos controllers para `LerClienteUseCase` (com `LeituraDoCliente`) e `ConferirDocumentoDoClienteUseCase`, sem sessão, `TenantContext` nem TenantFilter. A janela "Detalhes do cliente" e o aviso de documento já cadastrado passam a usá-los, sem mudança na tela. `PendenciasDoCadastro::porAbaVistoPor` conta as pendências por quem olha, dado por parâmetro.
- Conector de I.A. (spec mcp-remoto-oauth, PR 11): ferramenta `ler_clientes` com `ler` (nome, tipo, estado civil, profissão, nacionalidade, cidade, UF, quais campos estão preenchidos e quais faltam, pendências e pastas visíveis), `conferir_documento` (só se o CPF ou CNPJ já é de um cadastro que a pessoa vê, com id e nome) e `documentos_de_identidade` (metadados, sem as pastas em segredo, contadas, nem as pastas com a aba Documentos oculta). Nenhum valor de documento, contato ou endereço sai. `buscar` e `documentos_do_cliente` chegam depois.

## Para o cliente
- Com o conector de I.A. ligado, o Claude ou o ChatGPT podem consultar um cliente do escritório: o nome, o tipo de cadastro, a cidade, o que já está preenchido e o que falta no cadastro, e as pastas dele que você pode abrir.
- O assistente não recebe CPF, CNPJ, RG, data de nascimento, e-mail, telefones nem endereço do cliente. Ele só fica sabendo se esses campos estão preenchidos.
- Ele também pode conferir se um CPF ou CNPJ já está cadastrado no escritório, para não criar cadastro repetido, e listar os documentos de identidade do cliente que estão nas pastas que você abre.
- Pastas ligadas a processo em segredo de justiça não aparecem para o assistente.
- A janela "Detalhes do cliente" da pasta continua igual.
