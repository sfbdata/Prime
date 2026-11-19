---
tipo: novidade
---
## Changelog
- Contribua: modo campanha "BlueJus Black" (desenho 08 de 19/11, `bj-campanha.js` e ESPEC_CAMPANHA; spec `docs/specs/contribua-campanha.md`). Tabelas `contribua_campanha`, `contribua_campanha_alteracao`, `contribua_campanha_participacao` e `contribua_campanha_devolucao` (migration `Version20261119120000`, que cria a campanha padrão desligada, de 19/11 a 30/11/2026; o Master liga no painel). Uma configuração gera a aba do topo (parcial `layout/_globais/campanha.html.twig`), a faixa `#black` e o item do FAQ do Contribua, a janela da campanha (`/contribua/campanha/janela`) e as regras publicadas (`RegrasDaCampanha`).
- Inscrição no Desafio por Pix estático (txid `BJB<id>`), uma por usuário, CPF (só hash e 2 últimos dígitos) e OAB; vale depois que o Master confirma o pagamento no painel. Multiplicador e prazos congelados na inscrição; com a campanha no ar o Master não reduz benefícios. Pedido de devolução dentro do prazo com chave Pix cifrada; o Master marca como devolvida. Trilha de toda alteração da configuração.
- Selos do desenho novo com raio de 4px (GRATUITO, contatos do Suporte, estado da Integração bancária).

## Para o cliente
- Chegou a campanha BlueJus Black: o Desafio de R$ 1,00, que entra no ar quando a equipe BlueJus ligar. Quem quiser se inscreve com R$ 1,00 no Pix e usa o BlueJus com acompanhamento da equipe; se não gostar, pede a devolução em até 30 dias e recebe R$ 2,00, sem precisar justificar.
- A aba "BlueJus Black" aparece no topo de todas as telas e abre a página da campanha, com o passo a passo, o que o Desafio inclui e as regras completas.
- Na página "Quem somos e contribua", uma faixa nova apresenta o Desafio e as perguntas frequentes explicam por que existe o Desafio se o BlueJus é gratuito. O BlueJus continua gratuito para todos.
- Na página da campanha, quem já se inscreveu acompanha a própria participação e pode pedir a devolução informando a chave Pix.
- No Painel do Master, a seção "Modo campanha" configura nome, valor, devolução em dobro ou triplo, datas, prazos, vagas e benefícios, confirma os pagamentos recebidos e marca as devoluções pagas.
