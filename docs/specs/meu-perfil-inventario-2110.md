# Spec: itens do inventário de 21/10 no Meu Perfil (PR A: 145, 147 e 148)

Risco: BAIXO (tela e leitura de preferência; sem migration). Inventário: `docs/inventario-desenhos-2110.md`, itens 145
a 148 (frente 7d). O item 146 (e-mail diário das 8h) fica no PR B.

## 145. "Notificações no sistema" passa a valer

Desenho 07 (prefT 'notif'): "Notificações no sistema · Sininho de prazos, metas e menções", ligada por padrão.

Decisão do coordenador (21/10/2026): desligada, o sininho fica em SILÊNCIO para a pessoa, no escritório da sessão:
- sem contador (base, `/notificacoes/count`, as abas Minhas e Gestão) e sem itens na lista do sino;
- o lançador da Central não salta nem toca por esses avisos (`ContadorDeAvisos` deixa de contá-los); o contador da aba
  Caixa conta só o que pede ação, como o sino, e a lista da Caixa continua mostrando tudo;
- vale no escritório do aviso (a preferência é por escritório);
- a notificação continua GRAVADA, aparece em "Ver todas" (`/notificacoes`) e na Caixa da Central; religar mostra tudo.

Exceção, também decisão do coordenador: o que pede ação para não perder acesso ou prazo legal nunca entra no silêncio
(`App\Profile\Service\SilencioDoSininho::SEMPRE_AVISA`):
- `conta_pedido_exclusao`: a conta fica suspensa a pedido até alguém decidir;
- `djen_publicacao` e `push_publicacao_pasta`: abrem prazo processual;
- `pasta_pedido_acesso` e `pasta_acesso_decidido`: alguém fica bloqueado até a decisão, e a decisão é para quem estava
  fora;
- `push_link_renovacao`: alguém de fora perdeu o acesso pelo link externo do Push e pediu renovação.

Limites conhecidos: o prazo cadastrado na Agenda é gravado como `evento_criado`, o mesmo tipo do compromisso comum;
separar os dois pede um tipo novo, fora deste PR. Não há tipo de convite que expira, OAB pendente nem acesso suspenso.

## 147. Modal "Alterar senha" no padrão do desenho

Desenho 07, ED.senha (L203), mesmo modal dos outros: cabeçalho azul com `bi-key` e "Alterar senha"; SENHA ATUAL, NOVA
SENHA (placeholder "Mínimo 8 caracteres") e CONFIRMAR NOVA SENHA; Cancelar e Salvar (`bi-check2`). O texto a mais
saiu. O rótulo do segundo campo no formulário passou de "Repita a nova senha" a "Confirmar nova senha".

## 148. "Central de vagas e currículos" leva ao Trabalho Externo

Desenho 03 L330 e 07 L209: o item do Master abre o Trabalho Externo na aba de vagas (`/trabalho-externo#mod=vagas`, que
o `trabalho-externo.js` já lê). Até ter tela ele abria "em preparação" (decisão do dono de 19/10); a tela existe, e o
desenho manda. A "Campanha por e-mail" continua "em preparação" (PR 132).
