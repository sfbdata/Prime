---
tipo: correcao
---
## Changelog
- Encaminhar via Chat I.A: o cartão do sistema de pró-bono ou de contrato no Registro da aba Dados passa a seguir a regra do registro do Financeiro (`ItensEncaminhaveisDaPasta::tipoDoAcesso`). Quem envia e quem recebe precisam ver o financeiro da pasta, e a referência do despacho grava `registro_financeiro`, então quem perde o financeiro depois lê "Item de pasta sem acesso". Antes, o cartão chegava a um colega com o Financeiro oculto, pelo próprio ↪ da tela ou por um POST montado à mão.
- A prévia do item (`GET /central/encaminhar/item`) passa a conferir o financeiro, também para o registro do Financeiro: sem ele, 404.

## Para o cliente
- Os registros automáticos de pró-bono e de contrato só podem ser encaminhados a quem vê o Financeiro da pasta.
