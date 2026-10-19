# Spec: várias inscrições na OAB (PR 3 do redesenho do Meu Perfil, VS 18.10.26)

## Estado da frente (sessão 7d, redesenho Meu Perfil e menu do usuário; atualizado em 19/10/2026)

Mesma seção nas specs `meu-perfil-pagina.md`, `meu-perfil-oab-inscricoes.md` e `equipe-da-conta.md`. Depois de uma
compactação de contexto, releia antes de continuar.

**Decisões do dono**
- 19/10: tudo o que está no desenho entra (03 EXPEDIENTE e 07 Meu Perfil, VS 18.10.26); nada existente sai.
- 19/10: fidelidade ao desenho é critério de pronto (lista ✓/✗ e capturas antes do PR); o navegador está liberado na
  porta 8102, com o banco isolado `demo_menu7d`.
- 19/10: "Convites do escritório" só para o usuário primário (e o Master), como no desenho; os itens do Master sem
  tela no sistema (Central de vagas, Campanha por e-mail) aparecem como "em preparação"; idioma do sistema entra só
  como seletor (tradução das telas é projeto à parte); ordem PR 1 (menu) e depois PR 2 (página).
- 19/10: excluir conta mantém a quarentena de 365 dias, recuperável pelo Master; nada do lado do primário diz
  "irreversível".
- Decisões da coordenação (19/10): o secundário edita a própria data de admissão, com auditoria; suspenso e em análise
  não recebem atribuição nova, e editar preserva quem já estava (`ColaboradoresAtribuiveis`).

**Contratos com outras sessões**
- b5: ordem das barreiras no `TenantContextValidatorListener`: (a) escritório suspenso ou excluído, (b) pedido de
  exclusão pendente (PR 5), (c) vínculo, (d) situação. Nomes "escritório suspenso" e "acesso suspenso / em análise".
  Quarentena = Tenant com `excluidoEm` (purga em `tenant_carencia_purga_dias`, 365); os 365 dias contam da decisão do
  Master. Entre o pedido e essa decisão, o pedido fica "suspensa a pedido". `PedidoDeExclusaoDeConta` "excluida" com
  escritório ativo = recuperada; o `ReativarContaUseCase` não mexe no pedido. O botão do Master é "Excluir conta".
- 0d: também acrescenta coluna em `user_tenant` (sem conflito de nome).
- 5d: presença, "Bater ponto" (módulo de Ponto existente), o parcial `layout/_globais/fechar.html.twig` e
  "Instalar aplicativo" no menu do usuário (vai avisar o arquivo).
- f6: põe o item Contribua e a linha do pageTitle; ponto de inclusão `contribua/_assinatura_no_perfil.html.twig`.
- 4b: dona do formulário de Evento (fica fora do PR de `ColaboradoresAtribuiveis`).
- "Comercial e e-mails": lê a tabela de indicações por um UseCase público de consulta, com limites (PR de Indicar).

**PRs e pilha**
- #53 menu do usuário: mergeado. Master em a7433b93 (19/10) trazido para #60, #65 e #69.
- #60 página Meu Perfil (`meu-perfil-pagina`, base master).
- #65 inscrições na OAB (`meu-perfil-oab`, EMPILHADO sobre #60): mergear depois do #60; em seguida trazer o
  origin/master para a branch.
- #69 equipe da conta (`equipe-da-conta`, base master). Sobrepõe #56 (menu-usuario.css/js, EquipeDoEscritorioRepository,
  _globais, _dropdown, _modais, profile/index), #60/#65 (_dropdown, profile/index) e #55 (listener).

**Fila de migrations** (a coordenação controla; renomear o timestamp para o atual antes de cada merge)
- #60 `Version20261019120728`, depois de #55 → #56 (o #52 entrou em 19/10, master em a7433b93).
- #65 `Version20261019130750`, depois do #60.
- #69 `Version20261019134246`, depois do #65.

**Pendências**
- Com a coordenação: terceiro campo do convite, Cargo (desenho) ou Perfil (implementado no #69).
- Depois de cada merge: trazer o origin/master para as branches que ficaram para trás e rodar a suíte.

**Próximo passo**
1. PR `ColaboradoresAtribuiveis` nos formulários (Chamado, Expediente, seletores de Pasta, consultas de Kanban,
   Tarefa e Agenda; não o de Evento), sem migration, com teste funcional por formulário provando que editar preserva
   o suspenso.
2. PR 5: excluir conta em 2 etapas (motivo; código de 6 dígitos por e-mail e a palavra EXCLUIR), barreira (b) e
   desistir, com migration.
3. PR Indicar o BlueJus (desenho 03, L320/L687), com migration e o contrato de leitura na spec.

Risco: MÉDIO (credencial profissional; a revisão do Master e o cadastro dependem dela).
Desenho: `docs/design/meu-perfil-18-10/07 - Meu Perfil - VS: 18.10.26.dc.html`, card "Registro OAB" (linhas 79 a 92),
modal "Inscrição na OAB" (127 a 132) e `_salvarOab`/`ofRemover` (216 a 230). Print do dono: card com `49613/DF`,
selo PRINCIPAL, "Verificada em 10/07/2026 16:56", chip "Confirmada" e lápis; modal com o seletor Principal/Suplementar.

## 1. Regras do desenho

1. Uma pessoa tem **zero ou mais inscrições**, cada uma com número, UF (seccional), tipo e situação.
2. **No máximo uma por UF** ("Cada seccional tem uma só inscrição por advogado").
3. **Exatamente uma PRINCIPAL** quando há inscrição. Escolher outra como principal rebaixa a atual a suplementar.
   A primeira inscrição nasce principal; as seguintes nascem suplementares.
4. Inscrição nova, ou com número ou UF alterados, fica **Pendente** ("Aguardando validação do Master"); sem mudança de
   número e UF, mantém situação e data de verificação.
5. **Remover** só suplementar, e só quando houver mais de uma inscrição. A principal não se remove (troca-se a principal).
6. Lista ordenada: principal primeiro, depois por UF. Subtítulo: "Ordem dos Advogados do Brasil" com uma inscrição;
   "N inscrições · M suplementar(es)" com mais.
7. Número: de 1 a 7 dígitos. O desenho aceita uma letra final (`^\d{1,7}[A-Z]?$`), mas o banco exige só dígitos no
   espelho (`chk_user_oab_numero`); em produção (19/10/2026) havia 8 OABs, a maior com 5 dígitos, nenhuma fora do
   formato. Pontos, traços e espaços são ignorados; letra recusa com a mensagem. UF: uma das 27 seccionais.
8. Recusa ao salvar volta com o modal aberto, os valores digitados e o erro dentro dele (desenho `erroTem`/`erro`).
9. Criar a principal a partir do espelho é idempotente e à prova de duas abas (`INSERT ... ON CONFLICT DO NOTHING`);
   trocar para uma principal nova grava rebaixando a antiga antes, numa transação (o índice parcial é imediato).
   A criação a partir do espelho é SQL cru e não passa pela auditoria do ORM (só copia o que já estava no User, que
   é auditado); as alterações seguintes da inscrição são auditadas. Envio duplo simultâneo cai no índice único e
   volta com aviso, sem 500.

Situação no sistema = `StatusOab` que já existe: NaoVerificada aparece como **Pendente**, Divergente como
**Não confirmada**, Confirmada como **Confirmada** (cores do desenho).

## 2. Dados

- Entidade nova `App\Auth\Entity\InscricaoOab`, tabela `inscricao_oab`: user (CASCADE), numero, uf, tipo
  (`principal|suplementar`), status (`StatusOab`), nome_oficial, verificada_em, criado_em, atualizado_em.
  UNIQUE (user_id, uf); índice único parcial (user_id) WHERE tipo = 'principal'. Auditavel. Não é por escritório:
  a inscrição é da pessoa (não é TenantAware), como os campos oab* do User hoje.
- **Espelho**: os campos `User.oab*` continuam e passam a ser a cópia da principal (o desenho faz o mesmo, `espelho`).
  Quem só precisa da principal (cadastro, convite, criar escritório, mapa, painel, editor de primários, Trabalho
  Externo) não muda.
- Migration com backfill: cada User com `oab_numero` vira uma inscrição principal com o status, o nome oficial e a
  data que já tem. Donos confirmados sem número (backfill de 01/07) ficam sem inscrição, como hoje.

## 3. Pontos de contato com outras frentes

- **Revisão de OAB do Master** (`/admin/platform/oab`, `ConfirmarOabManualmente`, `ReverterOab`, `VerificarOab`;
  frente b5): enquanto ela opera sobre o User, as três ações passam a atualizar também a inscrição principal (sem
  mudar a tela). Para a fila mostrar as suplementares, este PR expõe:
  - leitor `App\Auth\Repository\InscricaoOabRepository::paraRevisao(statuses, busca, pagina)` (global, só Master) e
    `doUsuario(User)`;
  - UseCases por inscrição: `ConfirmarInscricaoOabUseCase`, `ReverterInscricaoOabUseCase`,
    `VerificarInscricaoOabUseCase` (id da inscrição), que mantêm o espelho quando a inscrição é a principal.
  A b5 religa a fila e os botões quando quiser.
- **Painel do Master** (contagens de OAB): continua lendo o espelho. Se quiser contar as suplementares, usa o leitor.
- **Push Processual** (frente 0d): não lê a OAB do usuário (tem `oab_monitorada` própria). Nada muda.

## 4. Rotas do perfil

- `POST /perfil/oab` passa a receber `idx` (id da inscrição, vazio para nova), `numero`, `uf` e `tipo`.
- `POST /perfil/oab/{id}/remover` (CSRF) remove uma suplementar da própria pessoa (404 se não for dela).
- `POST /perfil/oab/verificar` continua verificando a principal (o CNA está dormente).
