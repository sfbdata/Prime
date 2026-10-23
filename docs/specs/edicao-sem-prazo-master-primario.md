# Editar e excluir sem prazo: Master e usuário primário (23/10/2026)

Frente `edicao-sem-prazo`, aberta em 23/10/2026 a partir de `origin/master`. Risco: MÉDIO (permissão). Pedido do dono,
repassado pelo coordenador (bluejus-72). Leitura obrigatória: `docs/AUTORIZACAO.md` (§2a Master, §2b primário).

## Estado da frente

- 23/10: levantamento dos prazos feito (seção 2); decisões do dono A e B recebidas; spec escrita. Migration: 150600.
- 23/10: implementado e revisado; decisões do dono sobre meta com despacho registrado e mensagens do TE (seção 2).

## 1. Regra

1. Os 8 pontos da seção 2 têm prazo para editar ou excluir. Hoje só o **autor** edita ou exclui, e só dentro do prazo.
   Nem o Master escapa (as janelas não passam pelo `PermissionChecker`).
2. **Master** (`ROLE_SUPER_ADMIN`) edita e exclui **qualquer** conteúdo desses pontos, inclusive o de outras pessoas,
   a qualquer momento. Sempre, sem opção de desligar. (O desenho Perfis 1.2 L813 já lista para o Master "Sem restrição
   de tempo para editar ou excluir".)
3. **Usuário primário** (`RegraDosPrimarios::ehPrimario`, perfil `isSystem`) tem o mesmo poder, no próprio escritório,
   **quando a opção do escritório estiver ligada**. Ligada por padrão.
4. Os demais seguem exatamente a regra de hoje (autor, dentro do prazo).
5. O **despacho da meta com código** continua imutável para todos, inclusive o Master (registro oficial,
   `TarefaMensagem::exigirNaoRegistrado`). Mensagens e eventos do sistema continuam sem edição.
6. Na página da meta sai o "(editado)" das mensagens antigas (como no desenho 04B), junto com a chave
   `metas.detalhe.editado` e o teste que a conferia.
7. A guarda de tenant e as demais permissões de cada tela (módulo, cadeado da pasta, pasta excluída somente leitura)
   continuam valendo: o poder novo tira só o **prazo** e a **autoria**.

## 2. Pontos com prazo (levantamento de 23/10, origin/master 870686b81)

| # | Conteúdo | Prazo | Regra hoje |
|---|---|---|---|
| 1 | Registro e mensagem da pasta (`PastaMensagem`), o antigo "relatório 24h" | 15 min | `JanelaDeEdicaoDeComentario`, `Editar/ExcluirMensagemPastaUseCase` |
| 2 | Observação da aba Detalhes | 15 min | idem, `Editar/ExcluirObservacaoDetalhesUseCase` |
| 3 | Observação do Financeiro | 15 min | idem, `Editar/ExcluirObservacaoFinanceiraUseCase` |
| 4 | Nota técnica do processo | 15 min | idem, `Editar/ExcluirNotaTecnicaUseCase` |
| 5 | Excluir meta | 24h, criador | `ExcluirTarefaUseCase::podeExcluir`, `meta_pode_excluir` |
| 6 | Anotação da cobrança | 48h, autor | `EventoHistorico::podeSerEditadaPor` |
| 7 | Desfazer qualificação de contato (cobrança) | 5 min, quem registrou, só a mais recente | `EventoHistorico::podeSerDesfeitaPor` |
| 8 | Apagar mensagem da demanda do Trabalho Externo | 60 min, autor (o Master do TE já pode sempre) | `PoliticaDaDemanda::podeApagarMensagem` |

Fora (não são prazos de edição): tokens, convites, quarentena, lixeira, limites de envio, "Desfazer" da reabertura da
meta (10 min de sessão) e os itens sem prazo (Kanban, agenda, prazo da meta, justificativa do ponto, chamado).

Decisão do dono B: o item 7 entra (5 min para todos; Master sempre; primário com a opção). A condição "só a mais
recente" continua para todos, porque é de consistência do histórico e não de prazo.
Item 8 (decisão do dono, 23/10): o primário que vale é o do escritório dono da demanda, e ele só apaga mensagens de
autor com vínculo ativo nesse escritório; as da Central e de convidados de outros escritórios, não. O
`Perfil::Master` do TE continua como está. A opção é lida do escritório dono, sem o filtro de tenant da sessão.

Item 5, meta com despacho registrado (decisão do dono, 23/10): a exclusão é recusada para todos com "Esta meta tem
despacho registrado e não pode ser excluída". Master e primário (com a opção) veem o aviso numa janela de confirmação
(componente de janela das Metas) e só excluem confirmando de forma explícita; sem a confirmação o servidor recusa. A
auditoria registra a exclusão com os códigos dos despachos apagados.

## 3. Onde fica a opção do primário

Decidido: **tabela própria por escritório** (`escritorio_configuracao_edicao`, migration 150600), no padrão da
`ConfiguracaoDePresenca`, e **não** uma permissão no catálogo do perfil. Motivos (`docs/AUTORIZACAO.md` §2b):
- o primário (`isSystem`) passa por cima de qualquer permissão no `PermissionChecker`, e o D5 deixa as caixas do perfil
  primário marcadas e travadas; uma caixa nova seria sempre verdadeira e nunca poderia ser desligada;
- o catálogo é de permissões concedíveis a secundários, e esta é só do primário; o padrão "só primário" já vive fora
  do catálogo (`RegraDosPrimarios`).

Tabela: `tenant_id` único, `primario_sem_prazo` booleano (padrão verdadeiro; sem linha vale ligado), quem alterou e
quando. `TenantAware`, `Auditavel`, na `ORDEM_DELECAO` da purga.

Uma opção por escritório: vale para todos os primários dele. **Só um primário liga ou desliga** (guarda
`ehPrimario`; o Master não precisa dela). Tela: a página do perfil "Usuário Primário" em Perfis
(`app_tenant_role_edit` → `renderPrimario`), que é "o perfil" do pedido do dono. O componente é a linha de checkbox
do desenho Perfis 1.2 (21.10, L448: checkbox 16px, `accent-color:#0a6fa0`). Rótulo: "Usuário primário pode editar e
excluir qualquer registro sem limite de tempo". Gravação por POST com CSRF; sem a guarda, 403.

## 4. Ponto único da regra

Serviço novo `PoderDeEdicaoSemPrazo` (domínio Tenant), com uma pergunta só: dado o usuário e o escritório, devolve
`Master`, `Primario` ou nada. Cada um dos 8 pontos passa a perguntar:
`(é o autor E dentro do prazo) OU poder !== nada`. A regra fica no servidor (UseCase/serviço) e a tela usa a mesma
resposta (função Twig ou DTO), como já faz com `comentario_editavel` e `meta_pode_excluir`. Nada de checar papel no
template.

## 5. Selo "EXCLUSIVO DO MASTER / DO PRIMÁRIO"

Quando o botão de editar ou excluir só aparece por causa do poder (fora do prazo, ou conteúdo de outra pessoa), ao
passar o mouse (e no foco do teclado) aparece o selo dourado. Componente do desenho 03 EXPEDIENTE (L324), que o
sistema já tem em `menu-usuario.css` (`.mu-grupo-rotulo`): `bi-star-fill`, 10.5px, negrito, `letter-spacing:1px`,
`color:#9a6a00`, texto "EXCLUSIVO DO MASTER" ou "EXCLUSIVO DO PRIMÁRIO". Fundo escuro usa `#ffd770` (07 L47).
Só quem tem o poder vê (os outros nem veem o botão). Texto em translations, sem travessão.

## 6. Auditoria

Toda edição ou exclusão feita pelo poder novo em conteúdo de **outra pessoa** fica registrada: quem fez, o papel
(Master ou primário), o conteúdo (entidade e id) e a ação. As entidades dos 8 pontos que não forem `Auditavel` passam
a ser, ou o registro é gravado explicitamente pelo serviço de auditoria da casa. Conteúdo particular (texto) segue a
regra `ConteudoParticular` já existente.

## 7. Testes

- Unit do `PoderDeEdicaoSemPrazo`: Master sempre; primário com a opção ligada e desligada; secundário nunca; primário
  de outro escritório nunca.
- Para cada um dos 8 pontos (functional): autor dentro do prazo pode; autor fora do prazo não; terceiro não; Master fora
  do prazo e em conteúdo alheio pode; primário pode com a opção ligada e não pode com ela desligada.
- Despacho com código: Master não edita.
- Opção: só o primário grava (secundário e Master sem vínculo de primário levam 403); padrão ligado sem linha.
- Selo aparece só no botão liberado pelo poder; arranjo por combinador de filho direto.
- Auditoria registra a edição e a exclusão de conteúdo alheio.
- Página da meta sem "(editado)".
