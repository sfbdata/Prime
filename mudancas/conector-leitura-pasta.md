---
tipo: novidade
---
## Changelog
- Conector de I.A. (spec mcp-remoto-oauth, PR 6): ferramentas de leitura `buscar_pastas` (buscar, contar, opções de filtro, lixeira do escritório, número, minhas demandas), `ler_pasta` (resumo, andamento, registro, prazos, metas, detalhes, checklist, financeiro, cadastro, equipe) e `ler_agenda` (agenda e Minhas Metas).
- Tabela de aba e chave por ferramenta e ação (`TabelaDeAbaEChave`) e `GuardaDaAcaoDoConector`: ação, chave do Master, módulo, escritório, ver a pasta e abas conferidos a cada chamada. Pasta em segredo de justiça sai das listas e é contada (`SegredoNasListas`); pasta trancada segue a Lista do navegador.
- `LerAndamentoDaPastaUseCase`, Registro paginado (`PastaMensagemRepository::paginaDoRegistro`) e `PoliticaDeMascaramento`: no conector, a máscara de dados pessoais fica sempre ligada; no chat, nada muda.
- Minhas Metas pelo conector: `ConsultarPrazosEMetasUseCase` aceita a porta do chamador (`PortaDaConsultaDePrazos`); a do conector confere o módulo Metas e a liberação do conector.

## Para o cliente
- Com o conector de I.A. ligado, o Claude ou o ChatGPT conectado à sua conta passa a procurar pastas, ler o resumo, o andamento, o Registro, os prazos e as metas de uma pasta, e consultar a sua agenda e as suas metas.
- O app só enxerga o que você já vê no BlueJus: abas ocultas e pastas em segredo de justiça ficam de fora; da pasta trancada, só o número e o cliente, como na lista; CPF, CNPJ, telefone e e-mail chegam mascarados.
- Por enquanto o conector só é liberado para o escritório piloto.
