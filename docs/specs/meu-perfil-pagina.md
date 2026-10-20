# Spec: página Meu Perfil (PR 2 do redesenho, VS 18.10.26)

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
- #60 `Version20261020131000` (renomeada em 20/10 a pedido da coordenação; master 88912605), primeiro da pilha.
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

Risco: MÉDIO (dados do vínculo da pessoa com o escritório; a data de admissão aparece no espelho de ponto).
Desenho: `docs/design/meu-perfil-18-10/07 - Meu Perfil - VS: 18.10.26.dc.html` (linhas 52 a 118, 123 a 175 e o
script das linhas 179 a 272). Onde o desenho mostra, ele manda; onde é omisso, vale a convenção do sistema.

## 1. O que a tela mostra

1. **Identificação**: capa com o logo BlueJus à direita; avatar 104px com iniciais (primeiro e último nome) ou
   foto e o selo de câmera; nome em caixa alta; selo ★ MASTER (ROLE_SUPER_ADMIN) e selo do papel
   (USUÁRIO PRIMÁRIO ou SECUNDÁRIO); cargo e lotação do vínculo; botão "Editar informações"; a frase do perfil
   (ou "Adicione uma frase ao seu perfil" em itálico).
2. **Cadastro incompleto** (só abaixo de 100%): percentual, barra, chips do que falta e "Completar agora".
   Itens contados: foto, telefone, CPF, nascimento, frase, OAB e, conforme o vínculo, CTPS (CLT) ou início do
   contrato (demais). Percentual = (preenchidos + 2) / (itens + 2), arredondado (nome e e-mail sempre contam).
3. **Grade de dados** (rótulo "Não informado" em cinza quando vazio; copiar no hover para e-mail, CPF, CNPJ e
   telefone): E-mail, Vínculo ("Empregado CLT · bate ponto" etc.), CPF; conforme o vínculo:
   CLT = CTPS, Série, Admissão; Estagiário = Início do estágio; PJ = CNPJ, Início do contrato;
   Associado e Sócio = Início do contrato; depois Nascimento, Membro desde, Telefone.
4. **Preferências** ("Valem só para você"): Idioma (abre o seletor do PR 1), Notificações no sistema,
   Sons de alerta e Resumo diário por e-mail (chaves liga/desliga, padrão ligado).
5. **Segurança**: o card que já existe, com o texto do desenho.
6. **Revisão mensal**: uma vez por mês o pop-up "Seu cadastro está atualizado?" com o percentual e o resumo
   (Telefone, Vínculo, OAB principal). "Está tudo certo" grava a data da confirmação; "Atualizar agora" abre
   o modal. O "já mostrei neste mês" fica no navegador (conveniência de tela); a confirmação, no servidor.

Fora deste PR: várias inscrições na OAB (PR 3), Excluir conta (PR 5). O card da OAB continua o atual.

## 2. Modal "Editar informações"

- **Vínculo com o escritório** (5 botões: Empregado CLT, Associado, Prestador / PJ, Sócio, Estagiário) com a
  ajuda de cada um. **Só o primário do escritório (ou o Master) altera**; o secundário vê os botões travados
  e "Definido pelo escritório. Para alterar, fale com o usuário primário." O servidor ignora o vínculo vindo
  de quem não pode alterar.
- Nome completo, Frase do perfil, Telefone / WhatsApp, CPF (11 dígitos se informado), Nascimento.
- Conforme o vínculo escolhido: CLT = CTPS, Série, Data de admissão; PJ = CNPJ (se houver) e Início do contrato;
  Estagiário = Início do estágio; Sócio = Entrada na sociedade; Associado = Início do contrato.
- Vínculo ausente é tratado como Empregado CLT (padrão do desenho).
- Bate ponto: CLT e Estagiário. Só rótulo nesta entrega: nenhuma regra do módulo Ponto muda.

## 3. Dados

| Dado | Onde | Por quê |
|---|---|---|
| telefone, revisado_em | `user_profiles` | dado da pessoa, vale em qualquer escritório |
| tipo do vínculo, início do contrato, CNPJ | tabela nova `vinculo_no_escritorio` (tenant, user, único) | é do vínculo com AQUELE escritório; tabela própria no domínio Profile em vez de crescer a entidade legada `UserTenant` |
| data de admissão | `user_tenant.data_admissao` (já existe) | o espelho de ponto já lê daí |
| preferências | `preferencia_usuario` (já existe), chaves `perfil.notificacoes`, `perfil.sons`, `perfil.resumo_email` | catálogo fechado do Profile |

Isolamento: `vinculo_no_escritorio` é TenantAware; a leitura e a gravação partem do escritório da sessão.

## 4. Rotas

- `POST /perfil/dados` (já existe): passa a gravar os campos novos.
- `POST /perfil/preferencia` (JSON `{chave, ligado}`, CSRF do header AJAX): grava uma preferência do catálogo;
  chave fora do catálogo responde 400.
- `POST /perfil/revisao` (CSRF): grava a confirmação mensal.

## 5. Pergunta em aberto (enviada ao coordenador)

O desenho deixa o secundário editar a própria data de admissão e início do contrato (só o vínculo fica
travado). A admissão sai no espelho de ponto. Implementado como no desenho; se o dono preferir travar
também as datas para o secundário, é uma condição só no UseCase.
