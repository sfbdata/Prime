---
tipo: novidade
---
## Changelog
- Chat I.A propõe cadastro de cliente e de pasta: a pessoa manda o que tem (texto, CNH, ficha, contrato) e a I.A monta um cartão na conversa com os dados, que podem ser editados. A I.A nunca grava: só o clique em Confirmar cria o cliente ou a pasta, com a sua permissão e no seu escritório. Cancelar não grava nada; a proposta vence em 24 horas.
- Avisos no cartão: CPF ou CNPJ que já está cadastrado (com a opção de usar o cadastro existente na pasta) e pasta parecida já aberta (só segue com "Criar mesmo assim").
- Provedor da Anthropic com tool use (ferramentas `propor_cliente` e `propor_pasta`, oferecidas só a quem tem permissão); prompt `chat-livre-v3`; migration `Version20261028180000` (tabela `chat_ia_proposta`).
- Proposta é auditada (propor, confirmar, cancelar: quem e quando) sem gravar CPF, nome nem endereço; os dados somem da proposta ao decidir ou vencer, e a tabela entra na purga do escritório.
