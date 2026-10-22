# Idioma do sistema (bj-idioma)

Sessão 0d, 21/10/2026. Itens marcados **DONO** são decisões dele; o resto segue a convenção do sistema.

## Estado

- Proposta aprovada pelo coordenador (21/10): catálogo fixo e revisado, sem IA na tela.
- Entregas 1 e 2 no PR desta branch (migration `Version20261020148600`), com só o português liberado.
- Entrega 3 em diante espera as respostas do dono (§6), exceto a extração: ela entra com só o português liberado, sem
  mudança visível, e serve para qualquer resposta (combinado com o coordenador, 21/10).
- Entrega 3 (sessão 7d, branch `idioma-moldura`, sem idioma novo liberado): moldura (base, barra lateral,
  `layout/_globais`), menu do usuário e Meu Perfil no catálogo. Detalhes em §8.
- Série da b5 (extração por área, sem idioma novo liberado), um PR por etapa:
  - **P1 Perfis** (branch `idioma-perfis`): `tenant_role/*`, `perfis.js`, os flashes e as recusas do
    `TenantRoleController`, as recusas da `HierarquiaDePerfis` e o aviso do `PedirAcessoAosPerfisUseCase`; bloco
    `perfis` (88 chaves) e o glossário comum `termos` (9). Detalhes em §8.
  - **P2a Painel do Master** (branch `idioma-master`): `master/painel.html.twig` inteiro (abas, Permissões, Telas e
    recursos) e, no `painel.js`, a moldura das seções e a Visão geral (KPIs, gráfico, mapa, estados que faltam,
    seccionais e janela da OAB), com as respostas do `SeccionaisOabController`; bloco `master` (259 chaves: `painel` 53,
    `seccionais` 8, `js` 198). Detalhes em §8.
  - **P2b Painel do Master** (branch `idioma-master-b`): as outras seções do `painel.js` (Contas e escritórios com o
    menu da conta e os pedidos de exclusão, Usuários e OABs, Armazenamento, Planos e limites, Segurança e auditoria e o
    PDF), as recusas das seccionais com chave na exceção, as respostas do `ContasDoSistemaController` e do
    `PlanosELimitesController` e os rótulos da faixa etária e do tipo de divulgação; o `painel.js` inteiro entrou no
    `templates-extraidos.json`. Bloco `master` com 450 chaves (a P2b somou 191: `js` 165, `seccionais` 15, `contas` 4,
    `planos` 5, `faixa_etaria` 2). Detalhes em §8. Os mapas (`mapa_brasil`, `mapa_mundo`, `mapa-*.js`) ficam para a P3.
  - **P3** (branch `idioma-master-c`): a Central de Notificações (`_notificacoes`, `notificacoes.js`), a seção
    Indicações (`_indicacoes`, sem o include da Central de envios; `indicacoes.js`), `masters.js`, `recursos.js` e o
    mapa mundial (`mapa_mundo`, `mapa-mundo.js`), com as respostas e recusas do `CentralDeNotificacoesController`, do
    `IndicacoesDoMasterController` e do `MastersDoSistemaController` e as recusas com chave da `RespostaRecusada` e da
    `OperacaoDeMasterRecusada`. Bloco `master` com 584 chaves (a P3 somou 134: `notificacoes` 34, `indicacoes` 14,
    `masters` 11, `mapa` 4, `js` 71). O mapa do Brasil (`mapa_brasil`, `mapa-brasil.js`) fica com a 4b. Detalhes em §8.
- Quem tem o quê fora da b5: o Comercial é da 5d (bloco de topo próprio `comercial:`, chaves `comercial.*`; um segundo `master:` de topo duplicaria a chave no YAML); o Relatório do Comercial e os
  Temas são da 9b.


## 1. O que o desenho mostra

Fontes: `docs/design/perfis-1.2/vs-21-10-26/bj-idioma.js` (8 KB, completo) e o desenho "07 - Meu Perfil -
VS 18.10.26" (L96-98 e L257). O "05 - Perfis 1.2 - VS 21.10.26" só carrega o `bj-idioma.js` (L15), sem
seletor próprio nem escolha no painel do Master (confirmado pela b5, 21/10).

- **Onde se escolhe:** Meu Perfil (desenho 07), bloco "Preferências · Valem só para você",
  item "Idioma do sistema · Muda todas as telas, menus e o Chat I.A", com o nome do idioma atual. O item
  "Idioma" do menu do usuário, que já existe, veio do desenho 03 (`menu-usuario.js`). Os dois abrem o modal "Idioma do sistema": grade de 2 colunas, sigla + nome, o atual marcado com ✓.
- **Idiomas (10):** Português (Brasil), English, Español, Français, Italiano, Deutsch, Русский, 中文, 日本語,
  العربية (árabe da direita para a esquerda: `dir="rtl"`).
- **Regra do texto do modal:** "Vale só para você e muda todas as telas, menus, avisos e o Chat I.A. Nomes de
  pessoas, números de processo, CPF e citações de lei continuam como estão. Datas e valores seguem o formato do
  idioma escolhido."
- **Como o protótipo traduz:** escolha no `localStorage` e recarga; tradução automática de toda a página pela
  IA (`window.claude.complete`), com cache por idioma, varrendo o DOM (textos, `placeholder`, `title`,
  `aria-label`); datas e números pelo `toLocale*` do idioma.
- **O próprio desenho diz como fazer no sistema real** (bj-idioma.js, L3): "Symfony Translation
  (translations/messages.<locale>.yaml + trans no Twig), locale salvo no perfil do usuário."

## 2. O que o sistema tem hoje

| Item | Estado |
|---|---|
| Seletor | Modal "Idioma do sistema" no menu do usuário e no Meu Perfil, já com os 10 idiomas; só o Português ativo, os outros "em breve" (decisão do dono, 19/10/2026; `templates/menu_usuario/_modais.html.twig`). Item `idioma` no `CatalogoDeRecursos` do Master. |
| Locale | `framework.default_locale: pt_BR`; `symfony/translation` instalado; `translations/` só com `security.pt_BR.yaml`. Nenhum listener de locale; o perfil não guarda idioma. |
| Textos | 287 templates Twig (58 mil linhas) e 87 arquivos JS próprios (44 mil linhas) com texto fixo em português; **1** template usa `trans`; nenhum `TranslatorInterface` em `src/`; 379 `addFlash`; cerca de 620 exceções com mensagem em português que vão para a tela; 24 templates de e-mail. |
| Datas | 272 `|date(...)` com formato fixo (`d/m/Y` 119, `d/m/Y H:i` 63, `H:i` 43...); 41 `toLocale*('pt-BR')` no JS. |
| Valores | `number_format` com `,` e `.` fixos no Twig; `Intl.NumberFormat('pt-BR')` no JS. Moeda é sempre real (BRL). |
| Chat I.A | Os prompts pedem "português do Brasil" fixo (`PromptDoAgente`, `PromptResumoDoPush`...). |

## 3. Riscos que pesam na decisão

1. **Tradução automática na página, como no protótipo, não serve para o sistema real:** ela mandaria à IA o
   texto da tela inteira, que inclui nome de cliente, dados de processo e conteúdo das pastas (dado pessoal e
   sigiloso de terceiros). Também traduziria de forma diferente a cada página, com custo e espera a cada tela
   nova. Proposta: catálogo de tradução fixo, revisado, como o próprio desenho indica para o sistema real.
2. **Escala:** traduzir tudo é tirar cerca de 100 mil linhas de texto fixo para catálogos. Por isso, entregas por área, e
   cada idioma só é liberado no seletor quando o catálogo dele cobre o que já foi extraído (ver §5).
3. **Termos jurídicos brasileiros** (pasta, processo, Push, CNJ, peticionar, apensado) não têm equivalente direto;
   precisam de glossário fixo por idioma, senão cada tela diz uma coisa.
4. **Árabe (rtl)** espelha o layout inteiro (Bootstrap RTL, ícones, trilhos, menus). É o idioma mais caro.

## 4. Proposta técnica (convenção do sistema)

- **Escolha salva no perfil** (`user_profile.idioma`, migration de 1 coluna, padrão `pt_BR`), não no
  `localStorage`: vale em qualquer aparelho e nos e-mails. Rota POST com CSRF; recarrega a página.
- **Locale da requisição** por um listener (`kernel.request`) que lê o idioma do usuário logado; deslogado,
  `pt_BR`. `enabled_locales` com os idiomas liberados. `<html lang>` e `dir="rtl"` no `base.html.twig`.
- **Textos no Twig** por `{{ 'chave'|trans }}`, catálogos ICU em `translations/messages+intl-icu.<locale>.yaml`,
  chaves por área (`menu.usuario.idioma`, `pasta.processo.vincular`...). Português continua sendo a fonte.
- **Textos no PHP** (flash, exceção mostrada ao usuário, e-mail) pelo `TranslatorInterface`, com o locale de
  quem lê (no e-mail, o idioma do destinatário, não o de quem disparou).
- **Textos no JS** por um catálogo do idioma entregue na página (`window.BJ_T`, só as chaves da área), com uma
  função `t('chave', {x})`; nada de tradução no navegador por IA.
- **Datas e valores** por filtros de formato no lugar do formato fixo (`format_date`/`format_number` do
  `twig/intl-extra`, ou filtros próprios `data`, `data_hora`, `valor`), e no JS um `BJFormato` que lê o
  `<html lang>`. Moeda continua BRL; só o formato muda. Fuso horário não muda.
- **Chat I.A:** o prompt recebe "Responda em <idioma>" pelo idioma de quem pergunta. Nomes, números de processo,
  CPF e citações de lei ficam como estão (regra do modal).
- **Não se traduz:** conteúdo digitado pelas pessoas, documentos e peças, dados vindos do CNJ e do Push, nomes,
  números, CPF/CNPJ, citações de lei.
- **Guardas de teste:** `lint:translations` e `debug:translation --only-missing` no CI; teste de arquitetura que
  impede texto fixo novo nos templates de áreas já extraídas; o português (a tela de hoje) não pode mudar.

## 5. Entregas em PRs pequenos

| PR | Conteúdo | Migration |
|---|---|---|
| 1 | Idioma no perfil, listener de locale, `enabled_locales`, modal grava e recarrega, `lang`/`dir` no base. O seletor continua só com o Português ativo. | sim (1 coluna; número pedido ao coordenador na hora) |
| 2 | Formatos de data e valor por idioma: filtros `|data`, `|data_hora`, `|hora` e `|numero` (Twig) e `BJFormato` (JS), iguais aos de hoje em português. A troca dos formatos fixos (272 no Twig, 41 no JS) vai em cada PR de área (4..N), junto com a extração dos textos, para não mexer em ~150 templates de uma vez. | não |
| 3 | Extração da moldura: base, barra lateral, menu do usuário, Meu Perfil, avisos comuns e o modal de idioma. | não |
| 4..N | Extração por área, uma por PR (Expediente, Pasta por aba, Agenda, Clientes, Cobrança, Painel do Master...), com o PHP (flash e exceção) da área junto. | não |
| E | E-mails no idioma do destinatário. | não |
| IA | Idioma no prompt do Chat I.A e das análises. | não |
| L | Para cada idioma liberado: catálogo completo das áreas extraídas, glossário, e o idioma sai do "em breve". | não |

Áreas ainda não extraídas continuam em português mesmo com outro idioma escolhido; o seletor avisa isso
enquanto a extração não termina (**DONO**: ou só liberar o idioma depois de tudo extraído).

## 6. Decisões do DONO

1. **Quais idiomas e em que ordem.** Proposta: inglês e espanhol primeiro; o árabe (rtl) por último ou fora.
2. **Quem traduz.** (a) tradução profissional; (b) tradução por IA uma vez, no desenvolvimento, revisada por
   alguém que domine o idioma e com glossário jurídico; (c) tradução automática na tela, como no protótipo
   (desaconselhada: manda dado de cliente à IA e não é estável, §3.1).
3. **Liberar o idioma por área ou só com o sistema inteiro extraído** (§5, última linha).
4. **Os idiomas "em breve" continuam visíveis** no seletor até ficarem prontos, como hoje, ou somem.
5. **Árabe:** fazer o layout rtl completo ou tirar o árabe da lista.

## 7. Arquivos compartilhados que as entregas tocam

`app/templates/base.html.twig`, `_sidebar`, `menu_usuario/*`, CSS global (rtl), `app/config/packages/*.yaml`
(`framework`, `translation`), `composer.json` (se entrar `twig/intl-extra`), e quase todos os templates nas
fases 4..N. Por isso, cada PR de extração precisa de aviso de sobreposição e de ir rápido ao merge. O Meu
Perfil é também frente da 7d (gênero e atuação): o PR 1 combina com ela antes de tocar a tela.

## 8. Entrega 3: a moldura (7d, 21/10)

- **Catálogo:** `translations/messages+intl-icu.pt_BR.yaml`, um bloco por área (`moldura`, `menu`, `perfil`); cada PR
  de área mexe só no seu bloco. 340 chaves: moldura 84, menu 96, perfil 160.
- **JS:** `{{ catalogo_js('prefixo', ...) }}` (`App\Shared\Traducao\CatalogoJsExtension`) escreve um
  `<script type="application/json" data-bj-t>` com as chaves pedidas, no idioma da requisição e com o português no
  que faltar; `public/js/bj-traducao.js` (sem defer, no base) expõe `window.BJ_T.t('chave', {x})`. Chave do JS fica sob
  `<area>.js` e só usa `{x}` (sem plural ICU). O base entrega `moldura.js` e `menu.usuario.js`; cada tela entrega a
  sua (`perfil.js` no Meu Perfil). Chave ausente devolve a própria chave e avisa no console.
- **Guardas:** `SemTextoFixoNosExtraidosArquiteturaTest` (texto fixo novo nos arquivos de
  `tests/Arquitetura/templates-extraidos.json`, com os `permitidos` de cada área) e `CatalogoDeTraducaoTest`
  (`lint:translations` e toda chave usada no Twig e no JS existe em português).
- **Sem mudança visível:** o HTML de Meu Perfil, Expediente e Dashboard para primário, secundário e Master foi
  comparado antes e depois; só mudaram tokens CSRF e o JS que passou a ler o catálogo.
- **Formatos:** no Meu Perfil, 5 `|date('d/m/Y')` viraram `|data` e 1 `|date('d/m/Y H:i')`, `|data_hora` (nenhum
  depende do `|date` de nulo). A moldura e o menu não tinham formato fixo.
- **Ficou para a área de cada um:** texto que vem do PHP (rótulos de enum de Vínculo, Gênero, Atuação e StatusOab,
  `completude.faltando`, erros e flashes); os JS `presenca-cabecalho.js`, `conta-exclusao.js`, `lembretes.js`,
  `aviso-espaco.js`, `fechar-janelas.js`, `tema-comemorativo.js` e `pasta-busca-rapida.js`; os includes do base de
  outras áreas (`central/_flutuante`, `ponto/_modal_alerta_ponto`, `_recurso_faixa_master`,
  `layout/_cabecalho_acoes`, `contribua/*`); o "App já instalado ✓" do script inline do base (roda antes do
  `bj-traducao.js`).
- **Glossário comum (`termos.*`, P1 da b5):** bloco `# ---- termos`, logo antes do `# ---- fim`, com os termos do
  sistema em forma normal (`termos.master: Master`, `termos.primario: primário`, `secundario`, `perfil`,
  `escritorio`, `usuario`, `colaborador`, `pasta`, `processo`). A caixa alta fica no `|upper` (ou no CSS) de quem usa:
  `{{ 'termos.primario'|trans|upper }}` dá o selo PRIMÁRIO. O ICU não referencia outra chave: frase curta com o termo
  dentro recebe o termo por parâmetro (`'perfis.editar.tag_titular'|trans({papel: 'termos.primario'|trans})|upper`,
  com `tag_titular: '{papel} titular'`). Frase longa fica inteira na chave da área: o tradutor precisa da frase toda
  para a concordância.
- **Texto do PHP (P1 da b5):** o controller traduz no ponto em que o texto vai à tela (`TranslatorInterface`). A
  constante de recusa que outras telas e os testes comparam continua sendo o texto em português (a fonte), e a chave
  dela fica ao lado (`HierarquiaDePerfis::CHAVE_DA_RECUSA`); o `CatalogoDasRecusasTest` garante que o português do
  catálogo é igual ao da constante. Aviso gravado (Central) sai no idioma de quem recebe, lido do Meu Perfil dele,
  como no e-mail. A mensagem das exceções 403/404 não chega à tela (página de erro padrão) e fica em português.
- **Ficou fora da P1 (Perfis):** os rótulos, placeholders e a mensagem de validação do `TenantRoleType`; o nome
  exibido do perfil de sistema (`TenantRole::NOME_EXIBIDO_PRIMARIO`, "Usuário Primário"); as mensagens da
  `OperacaoDePrimarioRecusada` (promover e remover sócio), que o controller só repassa; os rótulos dos cartões de
  permissão (`GruposDePermissaoDoPerfil` e a descrição das permissões no banco); e o uso das recusas da hierarquia no
  `TenantController` e no convite, que continuam com o texto da constante. Contagens com "(s)" ("2 primário(s)",
  "N permissões") continuam como estão: plural de verdade mudaria a tela em português. Os Perfis não tinham data nem
  número com formato fixo.
- O rótulo ao lado do item Idioma do menu, que era "Português (Brasil)" fixo, passou a `idioma_atual().nome`, como no
  Meu Perfil (#199); com só o português liberado, a tela não muda (combinado com a 0d).
- **P2a (Painel do Master, b5):** bloco de topo `master:` (`# ---- master`, antes do `# ---- termos`), com
  `master.painel.*` (Twig), `master.seccionais.*` (PHP) e `master.js.*` (JS, entregue por
  `catalogo_js('master.js', 'termos')` antes do `painel.js`). O selo e as tags usam o glossário: `MASTER` é
  `'termos.master'|trans|upper`; `MASTER PRIMÁRIO` é `master.painel.masters.tag` (`'{papel} {nivel}'`) com
  `termos.master` e `termos.primario`, em `|upper`; no JS, a unidade singular da janela da OAB é `termos.escritorio`,
  `termos.primario` e `termos.usuario` (o plural mora em `master.js.janela_oab.*`).
- **Sem mudança visível (P2a):** o HTML do painel do Master primário (com Master secundário, candidato com e sem OAB) e
  do Master secundário foi comparado antes e depois; só entrou o `<script data-bj-t>` do catálogo. O texto que o
  `painel.js` monta foi comparado num DOM falso com dados fixos: 64 fotos (Visão geral em todos os modos do mapa e do
  gráfico, estados e países que faltam, seccionais, Editar sede com as mensagens, janela da OAB em três unidades,
  confirmação de remoção e, para os formatos, Contas, Usuários e OABs, Armazenamento, limites, Planos, as três seções
  vazias, auditoria e o PDF), iguais letra a letra.
- **Formatos (P2a):** no `painel.js`, `num` passou a `BJFormato.numero`, `dataHora` a `BJFormato.dataHora` e `dataBr`
  (`Y-m-d` do servidor) a `BJFormato.data`, lida como data local; o reais com centavos, `BJFormato.numero(v, 2)`. O GB
  (`1234,5 GB`), o eixo do gráfico em GB e o preço do campo "Editar plano" eram sem separador de milhar e continuam
  assim, por um `toLocaleString` com o idioma da página (`BJFormato.idioma()`) e `useGrouping: false` (o preço é relido
  pelo `centavos()`). Moeda continua BRL. O template não tinha data nem número com formato fixo.
- **Texto do PHP (P2a):** o `SeccionaisOabController` traduz no ponto de saída as respostas (`subseção adicionada`,
  `nota salva`, `sede atualizada`, `divulgação registrada`) e as recusas próprias (seccional não encontrada, token
  inválido); os testes funcionais que comparam o português continuam valendo.
- **Ficou fora da P2a:** as seções do `painel.js` da P2b (por isso o `painel.js` ainda não está no
  `templates-extraidos.json`) e o `QUEM = 'Master'` do PDF; as recusas dos UseCases das seccionais
  (`SeccionalRecusada`, `SeccionalNaoEncontrada`, `SubsecaoNaoEncontrada`, com `sprintf` de limite), que o controller
  repassa em português (precisam de chave na exceção: decisão para a P2b); o rótulo da faixa etária
  (`FaixaEtaria::FAIXAS`, "18 a 24", "55 ou +") e o do tipo de divulgação (`TipoDeDivulgacao::rotulo()`), que vêm do
  PHP como dado; os nomes dos países do Mundo e dos estados (`NM`), que são dado (o servidor manda o país em português e
  o painel compara com ele); os mapas e o `bj-dica.js`, `masters.js` e `recursos.js` (P3). Contagens com "(s)"
  ("N Master(s)", "N desativado(s)", "N duplicado(s)", "N conta(s) sem limite", "N usuário(s) sem data") continuam como
  estão: plural de verdade mudaria a tela em português.
- **P2b (Painel do Master, b5):** no `painel.js`, as chaves das seções novas moram em `master.js.*` (`contas`,
  `pedidos`, `menu`, `conf`, `situacao`, `faixa`, `usuarios_oab`, `arm`, `planos`, `limite`, `plano_modal`, `audit`,
  `pdf`, mais `sem_limite` e `erro_acao`), sem chave repetida da P2a (o título de Contas é `secao.contas`, o NOME do
  plano é `campo.nome`, o título das janelas de limite é o do item do menu). O `QUEM` do PDF cai em `termos.master` e o
  "(Master)" do PDF, do rodapé e da exclusão vêm de `termos.master` por parâmetro (`{papel}`).
- **Dado que fica como está (P2b):** a situação gravada da conta (`Ativa`, `Suspensa`, `Em quarentena`) vem do servidor
  em português e é comparada no filtro e no menu: o valor fica (`ATIVA`, `SUSPENSA`, `QUARENTENA`) e só o rótulo da
  tela sai do catálogo (`rotuloDaSituacao`); "Suspensa a pedido" virou o código `a_pedido`, que é só do painel. A
  palavra EXCLUIR da confirmação não se traduz (o `ExcluirContaPeloMasterUseCase` confere exatamente ela). A ação da
  trilha (`r.acao`) e o filtro dela são dado; as expressões que dão ícone e cor leem esse texto. O nome do plano, o da
  conta e o detalhe da trilha são dado.
- **Permitidos do bloco `master` (62):** os 27 nomes de estado (`NM`), os 28 países-alvo e o Brasil (`REG_MUNDO`, que o
  painel compara com o nome que o servidor manda), as três situações gravadas e `Content-Type`, `Escape` e `Enter`.
  Para o detector enxergar o arquivo, o `esc()` passou a escrever as aspas pelo código (`\x22`, `\x27`): o literal de
  aspas desalinhava a leitura das strings e escondia dele quase todo o `painel.js`.
- **Dívida (P2b, para decidir):** os nomes de estado (`NM`, só na tela) e de país (`REG_MUNDO` e "Brasil", comparados
  com o nome que o servidor manda e também mostrados) ficam em português em qualquer idioma. Traduzir os estados é
  mudar só o rótulo; os países pedem que o servidor mande um código (ISO) em vez do nome. **DONO:** se nome de lugar
  fica como nome próprio ou entra numa entrega própria.
- **Texto do catálogo em atributo (P2b):** todo `T()` que vai para `title`, `placeholder` e `aria-label` passa por
  `esc()`, para uma tradução futura com aspas não quebrar o atributo (em português nenhum desses textos tem `& < > " '`).
- **Recusas das seccionais (P2b):** `SeccionalRecusada`, `SeccionalNaoEncontrada` e `SubsecaoNaoEncontrada` estendem
  `RecusaDaSeccional`: cada uma tem as constantes `RECUSA_*` em português (com `%d` onde entra o limite) e o
  `CHAVE_DA_RECUSA`, no padrão da `HierarquiaDePerfis`. A mensagem da exceção continua o português (os testes dos
  UseCases seguem iguais); o `SeccionaisOabController` traduz pela `chave()` com os `parametros()`. O limite vai como
  texto ("1000", sem separador de milhar, como o `sprintf`). O `CatalogoDasRecusasDoMasterTest` amarra cada constante
  ao português do catálogo, e recusa fora das constantes dá `LogicException`.
- **Respostas dos controllers (P2b):** `ContasDoSistemaController` (suspensa, reativada, excluída, sessão expirada) e
  `PlanosELimitesController` (plano, faixas, limites, "Informe o espaço...", sessão expirada) traduzem no ponto de
  saída, em `master.contas.*` e `master.planos.*`. O `PainelMasterDadosController` só lê dados; nele, a faixa etária.
- **Rótulos do PHP (P2b):** o tipo de divulgação ganhou `TipoDeDivulgacao::chave()` (`master.seccionais.tipo.*`) e o
  `SeccionaisOabController` traduz o rótulo na lista (`ultima` e `ultimas`) e na resposta "Divulgação registrada"; a
  faixa etária ganhou `FaixaEtaria::chaveDoRotulo()` (`master.faixa_etaria.intervalo` e `ou_mais`) e o
  `PainelMasterDadosController` traduz o `rot` da Visão geral. O `rotulo()` e o `FAIXAS` continuam o português.
- **Sem mudança visível (P2b):** o texto que o `painel.js` monta foi comparado num DOM falso, HEAD contra o novo, com
  126 fotos em três variações (dados cheios, seções vazias e PDF sem o nome do Master): as 64 da P2a e mais 62 das
  seções da P2b (menu nas três situações, confirmações de suspender, reativar e excluir com sucesso, recusa e falha,
  filtros e busca, pendências de OAB nos quatro tipos, faixas inválidas, bloqueio e gravação, janela de limite nos
  dois tipos e em todas as mensagens, janela de plano, trilha com filtro, conta e vazia, PDF com e sem filtro), iguais
  letra a letra, incluindo os textos entregues à confirmação e os corpos dos POST.
- **Ficou fora da P2b:** as recusas dos UseCases das contas e dos limites (`ContaNaoEncontrada`,
  `OperacaoDeContaRecusada` e as `RECUSA_*` da `RegraDosMasters`, `PedidoDeExclusaoRecusado` do Tenant e as
  `\DomainException` da Contribua), que os controllers repassam em português: precisam de chave na exceção, como as das
  seccionais, e a da Contribua e a do Tenant são de outros domínios. A situação da conta, o nome do plano e a ação da
  trilha vêm do PHP como texto em português (dado): traduzi-los pede um código no JSON. Os nomes de estado e de país
  (dado, ver a P2a). O "R$" e a moeda BRL. Os mapas, `masters.js` e `recursos.js` (P3).

- **P3 (b5):** as chaves das áreas novas moram em sub-blocos próprios do `master:` (`notificacoes`, `indicacoes`,
  `masters`, `mapa`, e no JS `js.notificacoes`, `js.indicacoes`, `js.masters`, `js.recursos`, `js.mapa_mundo`; o
  `js.mapa` já era da Visão geral). O `notificacoes.js` e o `indicacoes.js` são carregados pelo parcial, antes do
  catálogo e do `bj-traducao.js` (fim do base): leem o texto só na hora de usar, e a carga pelo hash espera o
  `DOMContentLoaded`. O `masters.js` e o `recursos.js` usam o catálogo do painel (`master.js`, `termos`). O
  `mapa_mundo` (iframe sem o base) ganhou o próprio `catalogo_js('master.js.mapa_mundo')` e o `bj-traducao.js`; o
  `PainelMasterControllerTest::testCsp` passou a aceitar o bloco `type="application/json"` (dado, o navegador não
  executa). Datas e números pelo `BJFormato` (`dataHora`, `hora`, `numero`, `data`). Texto do catálogo que entra em
  HTML montado passa por `esc()`/`escH()`; o `esc()` do `recursos.js` escreve as aspas pelo código, como o do painel.
  O termo Master entra por `{papel}` nas frases do JS e nos flashes dos Masters. "(s)" fica como está.
- **Recusas com chave (P3):** `RespostaRecusada` (Central) e `OperacaoDeMasterRecusada` (promover e remover Master)
  ganharam as `RECUSA_*` em português e o `CHAVE_DA_RECUSA`, como as das seccionais (sem parâmetro; recusa fora das
  constantes dá `LogicException`); os UseCases lançam pelas constantes e os controllers traduzem pela `chave()`. O
  `CatalogoDasRecusasDoMasterTest` amarra as duas ao catálogo.
- **Sem mudança visível (P3):** o HTML de `/master` (Master primário) e de `/master/mapa/mundo` foi comparado antes e
  depois (templates da HEAD contra os novos, com tokens normalizados): o painel ficou igual (o catálogo `master.js`
  só ganhou as chaves novas) e o mapa mundial só ganhou o `<script data-bj-t>` e o `bj-traducao.js`. O texto que os
  cinco JS montam foi comparado num DOM falso, HEAD contra o novo, em 62 fotos (Central: lista, seleção, abas,
  páginas, estrela, falhas, mensagem com e sem e-mail, histórico nos três canais, respostas por e-mail e pela Central,
  recusa e falha; Indicações: carga, pausa, recusa e falha dos limites, carga que falha; Masters: sem escolha,
  promover, remover, confirmação com e sem palavra; Telas e recursos: essencial, prompts, desligar, recusas, falha,
  religar, busca vazia e por grupo, tudo ativo; mapa mundial: dicas de país, estado e município, painéis de país,
  estado e região, trilha, vazios e falhas), iguais letra a letra, incluindo os textos dos `prompt()` e os corpos dos
  POST, sem nenhuma chave faltando.
- **Permitidos (P3):** o bloco `master` passou a 71: entram as regiões (`Norte`, `Nordeste`, `Centro-Oeste`,
  `Sudeste`, `Sul`), os países do mapa mundial que o painel não tinha (`Austrália`, `Japão`, `China`) e `Polygon`
  (tipo de geometria do d3).
- **Nomes de lugar (DONO, 21/10):** os nomes de estado, de país e de região ficam em português nesta série; a tradução
  deles vira entrega própria depois (resolve a dívida da P2b).
- **Ficou fora da P3:** o mapa do Brasil (`mapa_brasil.html.twig` e `mapa-brasil.js`) fica com a 4b, que está mudando
  os dois e extrai o texto deles quando terminar; o `mapa-dados.js` (menu de dados públicos, OAB e IBGE), que o mapa
  do Brasil também carrega e que pediria o catálogo lá; o `mapa-agregado.js` e o `bj-dica.js` não têm texto (o
  `bj-dica.js` entrou no `templates-extraidos.json`; o `mapa-agregado.js` não, por ser também do mapa do Brasil). O
  conteúdo da Central (rótulo do tipo, "Sistema BlueJus", "Equipe", "Conta Nº", assuntos e textos montados nos
  leitores de `app/src/Master/Notificacoes/`, e o rótulo da sugestão, que vem do `TipoDeSugestao` do Contribua): a
  busca da caixa compara esse português e o assunto vai também ao e-mail e ao aviso da resposta, então traduzir pede
  um código no JSON e a busca no idioma da tela. As recusas do validador e do `AlterarLimitesDeIndicacaoUseCase`
  (domínio Indicacao, compartilhado com o lado do usuário). As `RECUSA_*` da `RegraDosMasters` (o
  `EscritorioController` do Tenant também usa). As respostas do `RecursosDoSistemaController` e do `RecursoRecusado`
  (fora do escopo desta etapa). O `lang="pt-BR"` fixo do `mapa_mundo`.

- **Termos comuns (21/10, depois do #231):** os selos MASTER e SECUNDÁRIO do menu e do Meu Perfil, o papel "Master"
  (Minha conta e aba do Meu Perfil) e o "primário" do JS do menu vêm de `termos.*` (`|upper` onde é caixa alta; o base
  entrega `termos` ao JS). "USUÁRIO PRIMÁRIO" (expressão composta, a ordem muda por idioma), "Perfis" e "Colaboradores"
  (plurais; o `termos` só tem o singular) ficam com chave própria.

## 9. Extração do Expediente (7d, 21/10)

- **Escopo:** `templates/expediente/*` (lista, acervo geral, paginação, modais, painel do marcador, trilho, relatório de
  processos e pastas paradas), `expediente-lista.js` e `expediente-trilho.js`, com os scripts inline do `index` e da
  `_lista`. Bloco `expediente` do catálogo, 397 chaves; o JS fica sob `expediente.js.lista` e `expediente.js.trilho`,
  entregue pelo `{{ catalogo_js('expediente.js') }}` do `index` (os dois JS chamam `BJ_T.t` na carga).
- **Sem mudança visível:** o HTML de 9 telas (lista com 60 pastas, prioridades e marcadores, página 2, busca vazia,
  marcador cheio e vazio, trilho, pastas paradas, relatório) saiu igual; mudaram só tokens, ids e o JS que lê o catálogo.
- **Guarda:** CSS guardado em `{% set estilo... %}` (a folha que vai à tela e ao PDF) conta como `<style>`.
- **Formatos:** `number_format` do resumo e do trilho viraram `|numero(0)`; `dataAbertura`, `excluidaEm`, a data dos
  relatórios e a última movimentação das pastas paradas viraram `|data`/`|data_hora` (nenhuma pode ser nula ali).
  Ficaram: `impressoEm|date('d/m/Y, H:i:s')` dos relatórios e `cad.em`, que o `ExpedienteController` formata no PHP.
- **Ficou para depois:** os textos dos DTOs dos relatórios (título, cartões, análise, nível Crítico/Atenção/Acompanhar,
  `NENHUMA`, `NOTA_FINAL`), mensagens de erro do servidor, `relatorio-processos.js` (rótulo do tamanho da fonte) e os
  parciais de outras áreas que a lista inclui (`pasta/_filtros`, `pasta/_filtro_situacao`, `pasta/_resp_*`,
  `_partials/modal_nova_pasta`, `_partials/_janela_confirmacao`). O `<html lang="pt-BR">` das páginas próprias dos
  relatórios e do PDF continua fixo.
- **Frase partida:** onde a frase tem aspas retas ou `<b>` no meio, ela foi dividida em chaves, com as aspas e a marcação no
  template (o `|trans` escaparia as aspas e o `<b>` pediria `|raw`), para o HTML sair idêntico. Na fase de tradução (L),
  quem traduz recebe a frase inteira junta, e o par de chaves pode virar uma só com parâmetro, se o idioma pedir outra
  ordem (ex.: `relatorio.nota_final.*`, o aviso do PDF do modal e a abertura das pastas paradas).

## 10. Extração do Minhas Metas (7d, 21/10)

- **Escopo:** `templates/tarefa/minhas`, `_metas_lista`, `_meta_cartao`, `_resultado`, `equipe` e `_numero_global`, com o
  script inline do `minhas`. Bloco `metas` do catálogo, 107 chaves; o JS do marcador lê `metas.js` (entregue pelo próprio
  `minhas`). O `_meta_cartao` e o `_numero_global` servem também à aba Metas da Pasta e ao `tarefa/show`, com a mesma saída.
- **Sem mudança visível:** o HTML das 4 abas, do modo lista, da busca vazia, de um filtro e da equipe saiu igual.
- **Formatos:** `'now'|date('H:i')` virou `|hora`; prazo, criação e alteração da meta, `|data`. O "Nº 0001" mantém o
  `'%04d'|format` (o `|numero` não põe zero à esquerda); só o texto em volta é chave.
- **Ficou para depois:** os rótulos que vêm do PHP (`AgrupadorDeMetas`, `StatusDoPrazoDaMeta`, `TextoDoPrazoDaMeta`,
  `Tarefa::prazoInfo`, fatias do andamento, prioridade da pasta, título e descrição das listas da equipe),
  `filtro-tabela.js` e `pasta-metas.js` (vão com a Pasta) e o placeholder padrão do `_partials/_filtro_barra`.

## 11. A Pasta, aba Processo (0d, 21/10)

- **Blocos de topo por aba**, combinados com a f6: `pasta_processo` (este), `pasta_push` (próximo PR da 0d) e
  `pasta_financeiro` (f6). O JS de cada aba lê `<bloco>.js`; o `catalogo_js` mora no próprio parcial, sem tocar o
  `pasta/show.html.twig`. O parcial que volta por XHR traz o bloco de novo, e o `BJ_T` relê (§8).
- **Extraídos:** `pasta/_processos_vinculados.html.twig` e `pasta-processo.js`. Os dois `|date` viraram `|data` e
  `|data_hora` (os dois com guarda de nulo).
- **Sem mudança visível:** o DOM da aba (texto e atributos, como o navegador lê) foi comparado antes e depois em
  quatro cenários (processo completo com apensado, partes, campos manuais e o formulário de edição; vazia com ação;
  administrativa sem e com processo). Só mudaram os tokens CSRF das notas técnicas, que são aleatórios. Os ramos só
  leitura foram renderizados à parte, iguais. No código-fonte, as aspas da dica da edição saem como `&quot;` (o
  autoescape do `|trans`); a tela é a mesma.
- **Ficou de fora:** o texto que vem do PHP (rótulos e placeholders de `dados_do_processo`, `resumo_do_processo`, a
  linha de fonte, o tipo do apensado, a instância e o filtro `situacao_processo`) e o `processo/_notas_tecnicas`, que
  é do domínio Processo e aparece também no teor do Push.

## 13. Extração do Cliente (7d, 21/10)

- **Escopo:** `templates/cliente/show`, `editPF`, `editPJ`, `_cadastro` (janela "Cadastro e qualificação do cliente") e
  `_resumo` (janela "Detalhes do cliente" da aba Clientes da Pasta), com o script inline do `show` (só o que roda no
  evento de anexar), e `cliente-cadastro.js`. Bloco `cliente` do catálogo, 277 chaves; 57 delas em `cliente.js.*`. O
  `show` entrega `{{ catalogo_js('cliente.js') }}` para o próprio script; as duas páginas que carregam o
  `cliente-cadastro.js` (`pasta/show` e `cobranca/carteira/index`) entregam o mesmo catálogo antes do `<script>`. O
  estado civil da qualificação é lido do catálogo na hora (não na carga do arquivo).
- **Sem mudança visível:** o HTML de 20 telas (ficha PF cheia e vazia, ficha PJ cheia e vazia, edição PF e PJ, janela de
  cadastro PF com pasta, autores e réus, aba Documentos com OCR, aba Histórico, PF vazio, PJ vazio e cheio, Novo cliente
  PF e PJ avulso e pela pasta, janela travada de quem só vê, Detalhes do cliente PF com pastas, PF sem contato, PJ e só
  leitura) saiu igual; mudaram só tokens, o script inline que lê o catálogo e os dois `confirm()` da ficha, que agora
  passam por `|e('js')` (o texto mostrado é o mesmo, o HTML traz os espaços como ` `).
- **Formatos:** `dataNascimento`, `rgDataEmissao`, `carregadoEm` e `uploadedAt` dos anexos, `clienteDesde` viraram
  `|data`; `criadoAt` e `modificadoEm` da aba Detalhes, `|data_hora` (todos protegidos por `if` ou nunca nulos). O
  tamanho dos anexos manteve o `|round` (ponto decimal, como antes); só a unidade "KB"/"MB" virou chave com parâmetro.
  O `|date('Y-m-d')` do campo de nascimento é `value` de `<input type="date">` e fica. No JS, a data de nascimento da
  qualificação continua montada à mão (`dd/mm/aaaa`).
- **Ficou para depois:** os rótulos dos campos da edição (vêm do `ClientePFType`/`ClientePJType`), os tipos de documento
  (`ClienteController::DOCUMENT_TYPES`, que também enchem o `<select>` do script inline), a situação da pasta
  (`pasta.situacao|capitalize`), o `documentoRotulo`, os rótulos e ícones dos contatos e as pendências do
  `ClienteResumoOutput`, as pendências e a qualificação do servidor (`PendenciasDoCadastro`, `QualificacaoDoCliente`), o
  histórico (`HistoricoDoCliente`), o JSON da inteligência cadastral (`RotulosDaInteligenciaCadastral`), as mensagens
  de erro e de sucesso do salvar (servidor) e os flashes do `ClienteController`. O valor inicial "brasileiro(a)" da
  nacionalidade é `value` enviado ao servidor e fica. Os parciais da Pasta que mostram cliente (`pasta/_cliente_linha`,
  `pasta/_push_cliente*`) e os JS `pasta-clientes*.js` e `pasta-push-cliente.js` vão com a Pasta.
- **Qualificação no JS:** os pedaços da qualificação ao vivo (`cliente.js.qual.*`, estado civil minúsculo) espelham o
  `QualificacaoDoCliente` do PHP, que ainda não lê o catálogo. Na fase de tradução os dois mudam juntos; o truque do
  gênero (`(a)` → `a`/vazio) só vale para o português e terá de ser revisto ali.

## 12. Extração do Dashboard (7d, 21/10)

- **Escopo:** `templates/dashboard/*` (casca com o cabeçalho do PDF, segmentado de período, selects e calendário
  próprios e menu ⋮; cards e tabela; lista do celular; colunas extras; tendência; painel BlueJus Intelligence) e os
  JS `dashboard.js`, `dashboard-filtros.js`, `dashboard-historico.js`, `dashboard-janelas.js` e
  `dashboard-preferencias.js` (este sem texto visível). Bloco `dashboard` do catálogo, 234 chaves; o JS lê
  `dashboard.js.*` (impressão, calendário, janela Histórico e janelas flutuantes), entregue pelo
  `{{ catalogo_js('dashboard.js') }}` do `index` antes dos scripts. Os meses e os dias da semana do calendário são
  chaves (`mes_1` a `mes_12`, `semana_curta_*`, `semana_longa_*`), lidas na hora de desenhar; o texto traduzido que
  entra no `innerHTML` do calendário passa por `esc()`.
- **Sem mudança visível:** o HTML de 18 respostas (primário com dados, com período do mês, com período do ano e
  ordem, com filtros de responsável/cargo/busca, "Sem cargo", busca vazia, fragmento XHR, com todas as colunas extras
  e o Modo avançado ligado, extras no XHR, secundário, escritório vazio com e sem período, e o JSON do Histórico dos
  4 cards) saiu igual; mudaram só tokens, horas, o catálogo do JS e a cor do avatar, que segue o id do usuário
  (`userId % 9`) e muda entre duas rodadas do teste.
- **Guarda:** dois `>` dentro de expressão (`ritmo.vencidas > 0`, `extras|length >= 2`) viraram `0 < ...` e
  `2 <= ...`, sem mudar o resultado, porque o detector lia o `>` como fim de tag. Em `permitidos`: a marca
  (BlueJus, Intelligence), as constantes `ColunasExtrasDoDashboard::CATALOGO/TENDENCIA`, o trecho `sóci` do mapa de
  ícone do cargo, `db-body` e os grupos `Atenção imediata`/`Acompanhamento`, que o template compara com o texto que
  vem do PHP (`LeituraDoRitmo`) para escolher a classe.
- **Formatos:** `'now'|date('d/m/Y')` do cabeçalho do PDF virou `|data`; `'now'|date('H:i')` (Atualizado às, PDF) e
  `geradaEm|date('H:i')` do painel, `|hora`. Ficaram: as datas `aaaa-mm-dd` dos filtros montadas por
  `split/reverse/join`, o `ui.fmt` do painel (`round(1)` com vírgula: o `|numero(1)` escreveria "2,0" onde hoje sai
  "2"), o arco do card global (`number_format(2, '.', '')`, valor de SVG) e, no JS, as datas e a hora do cabeçalho
  do PDF e do nome do arquivo, montadas à mão (`p2`), sem `toLocale*`.
- **Ficou para depois:** os textos que vêm do PHP: rótulo, ajuda e nota das colunas extras
  (`ColunasExtrasDoDashboard::CATALOGO`), toda a leitura do BlueJus Intelligence (`MontarLeituraDoDashboard`,
  `MotorAvancado`, `MotorDeRitmo`, `NivelDeAlerta::rotulo`, período, alertas, perguntas, riscos, ações, limites), o
  Histórico dos cards (`ObterHistoricoDoCardUseCase`: título, subtítulo, nota, meses, indicadores) e a recusa
  `Sem acesso ao módulo Dashboard.`. Os parciais de outras áreas que a casca usa: `_partials/_filtro_barra` (chips,
  rótulos e botões internos) e `filtro-tabela.js`. O `|lower` do nível no painel continua sobre o rótulo do PHP.

## 14. A Pasta, aba Push (0d, 21/10)

- **Bloco `pasta_push`** (269 chaves): `processual`, `painel`, `cliente`, `envio`, `teor`, `link` e `js`. Os quatro JS
  da aba (`pasta-push.js`, `pasta-push-painel.js`, `pasta-push-cliente.js` e `pasta-push-link.js`) leem `pasta_push.js`.
  O `catalogo_js` mora no `_push_processual` (antes do `pasta-push.js`; o painel e o cliente são incluídos por ele) e no
  `_push_link_externo` (o `pasta-push-link.js` é carregado pelo `pasta/show`, depois do parcial), sem tocar o show. Com
  a aba oculta, nenhum dos dois sai, e o `pasta-push-link.js` para no começo, sem o modal.
- **Extraídos:** `pasta/_push_processual`, `_push_painel`, `_push_cliente`, `_push_cliente_modal`, `_push_teor` e
  `_push_link_externo`, e os quatro JS. Não havia `|date` nesses parciais. A abreviação dos meses da pílula do dia
  ("5 out 2026") virou `pasta_push.processual.meses.*`. Contagem e plural do JS viraram pares de chaves (`um_acesso` e
  `acessos` com `{n}`), sem plural ICU. O "Esta pasta ainda não tem Análise IA." do link externo não entra no
  `pasta_push.js` (que vai à página de quem não vê IA): o JS guarda o texto que o parcial traz, só para quem vê IA.
- **Permitidos:** nomes de tecla e cabeçalho HTTP (`Accept`, `Escape`, `Tab`, `ContextMenu`, `ArrowDown`, `ArrowUp`) e
  as letras acentuadas do mapa que tira o acento do tipo da comunicação para escolher a cor (`ç`, `ã`, `á`...), que não
  vão à tela.
- **Sem mudança visível:** o HTML da aba (com o painel, o "Avisar cliente", o modal de envio e as três peças do link
  externo) foi comparado antes e depois em seis cenários (sem processo; sem publicação; completo com apensado, filtro
  por processo, publicação lida e não lida, sem teor e com análise da IA; o mesmo sem IA; quem só vê; pasta excluída),
  mais o teor de três publicações e o cartão e o modal do cliente renderizados à parte (com e sem e-mails,
  descadastrado, Controladoria e histórico). Só mudaram os tokens CSRF das notas técnicas, que são aleatórios.
- **Ficou de fora:** o texto que vem do PHP (status, subtítulo e última busca do painel, a Situação atual, os rótulos e
  dicas dos avisos e do envio automático, a sublinha e as dicas dos e-mails do cliente, o histórico, o subtítulo do
  modal, os rótulos dos filtros por processo, o tipo do apensado, o `estadoRotulo`, o `resumo` e o `motivoBloqueio` dos
  links, e os erros e avisos do JSON dos controllers); a mensagem de `ia_disponibilidade()`; os parciais de outros
  domínios incluídos pela aba (`inteligencia/_analises_push` e `processo/_notas_tecnicas`) e o `pasta-ia-push.js`; o
  `title` da aba no `pasta/show` (o `pasta-push.js` reescreve o mesmo texto pelo catálogo).
- **Permitidos fora da regra:** as 10 letras acentuadas do `|replace` que tira o acento do tipo da comunicação
  (`_push_processual`) são técnicas e não vão à tela; como a lista vale para os 10 arquivos do bloco, um literal de
  uma letra acentuada nesses arquivos passa pelo detector. O mesmo `catalogo_js` sai duas vezes quando os dois
  parciais estão na página (o `BJ_T` junta os blocos; só pesa no tamanho).

## 18. Extração da Agenda (7d, 21/10)

- **Escopo:** `templates/agenda/nova`, `lista`, `novo`, `editar` e `show` (as telas antigas continuam com link: a lista
  pela Agenda nova e pelo Dashboard, o show pelos avisos do sino; confirmado pela 4b), `agenda-nova.js` e `lembretes.js`
  (global). Bloco `agenda` com 344 chaves (o JS em `agenda.js.*`, entregue pelo `nova`; o placeholder do Select2 de
  novo/editar em `agenda.form.js.*`) e `moldura.js.lembretes.*` (21, entregue pelo base).
- **Sem mudança visível:** o HTML de 10 telas (Agenda nova, lista e passados, novo, show e editar de evento simples, dia
  inteiro e recorrente com local) saiu igual; mudaram só os `confirm()` dos `onclick`, agora `|trans|e('js')` (o mesmo
  texto, escapado para JS) e um valor padrão de hora que vem do relógio.
- **Formatos:** datas e horas da lista, do show e do editar viraram `|data`, `|data_hora` e `|hora`; no JS,
  `toLocaleTimeString`/`toLocaleDateString('pt-BR')` curtos viraram `BJFormato`; nomes de mês e dia da semana viraram
  listas de chaves. Ficaram: a data longa do detalhe (`weekday: 'long'`, o `BJFormato` não tem formato longo), o dd/mm do
  próximo feriado, as horas da grade e o mês abreviado da lista (corte em 3 letras do nome traduzido).
- **Ficou para depois:** rótulos do `EventoType` (formulário legado), `statusLabel` e `tipoRecorrencia` cru no show,
  mensagens de erro do servidor, e o texto do PHP listado pela 4b (`AvisoDoCompromisso`, `VarrerLembretesDePushUseCase`,
  `CompromissoNoGoogle`, `PrazoParaOGoogle`, `GoogleAgendaController`).

## 16. Cobrança: objeto, caso e acordo (0d, 21/10)

- **Escopo:** `cobranca/objeto/show` e os oito parciais de `objeto/_partials` (`_divida`, `_movimentos`,
  `_responsaveis`, `_telefones_cobrada`, `_painel_qualificacao`, `_honorarios`, `_anotacao_acoes`,
  `_campos_config_encargos`), `cobranca/caso/*` (lista, `_resultado`, `_documentos`, `_acoes_modais`,
  `_acoes_modais_financeiro`) e `cobranca/acordo/show`. Bloco `cobranca_objeto` do catálogo, 595 chaves, com
  `cobranca_objeto.js.*` para o JS inline do `show` do objeto e do acordo (entregue por `catalogo_js('cobranca_objeto.js')`
  antes do primeiro `<script>` de cada um). A lista de casos não tem JS com texto.
- **Sem mudança visível:** o HTML do objeto cheio (dívida, acordo vigente e rompido, pagamento, liquidação, anotação,
  próxima ação, responsáveis e telefones) e vazio, da lista de casos com dados, vazia e com busca sem resultado e do
  acordo saiu igual fora dos `<script>`. Mudaram só tokens CSRF, quebras de linha, o `<script data-bj-t>` do catálogo,
  os literais trocados por `BJ_T.t()` no JS inline e a codificação de `aria-label` com a descrição da obrigação ou o
  nome da pasta (antes `|e('html_attr')` no pedaço, agora o escape normal da frase inteira: o texto lido é o mesmo). Os
  `confirm()` de excluir documento (acordo e arquivo do caso) passam por `|e('js')`. A ordem das linhas da lista de casos
  com dois casos do mesmo instante variou entre as fotos (empate na ordenação, já existia).
- **Formatos:** viraram `|data` só as datas com guarda de nulo: data do acordo (título, cabeçalho, grupo da dívida e
  acordos encerrados), encargos atualizados e congelados, `pagoEm` do "Já pago", `totaisAtualizadosEm` e o prazo da
  próxima ação. As demais (`vencimentoOriginal`, `ocorridoEm`, `criadoEm`, o `m/Y` dos vínculos) seguem com `|date` e
  entram como parâmetro já formatado, como os `number_format` e os percentuais.
- **Frase partida:** as frases com `<strong>` ou `<em>` (encargos por obrigação, avisos dos modais de pagamento,
  obrigação, encerramento e judicialização, acordo com pagamento, configuração de encargos do objeto, exclusões) foram
  divididas em chaves, como no §8. `x > 0` dentro de atributo virou `0 < x`. Os sufixos `'Bp'`/`'Reais'` do macro de
  encargos viraram os nomes completos dos campos no próprio mapa (`jurosBp`, `jurosReais`...), sem mudar o form. O
  `R$ 0,00` fixo virou `{{ 0|centavos }}`, e o `R$` dos campos, `comum.simbolo_reais`. O nome do enum
  `App\\Cobranca\\Enum\\TipoTelefone` (em `enum_cases`) ficou nos permitidos.
- **Ficou de fora:** o texto que vem do PHP (`statusLabel`, `tipoLabel`, `familiaLabel`, `papelLabel`,
  `formaHonorariosLabel`, rótulos dos filtros do histórico, da qualificação de contato, do `TipoTelefone`, das categorias
  de documento e dos tipos de liquidação, descrições dos alertas, rótulos dos Form types, flashes e as mensagens JSON dos
  controllers que o JS mostra); os parciais de outras pastas (`cobranca/_partials/_subnav`, `_partials/_filtro_barra`,
  `_filtro_paginacao` e a ficha da pessoa carregada por AJAX, de `cobranca/pessoa`); os JS externos
  `judicializar-buscar-pasta.js`, `objeto-linha-menu.js`, `pasta-arquivos.js` e `editor-rico.js` (têm texto) e
  `filtro-tabela.js` e `telefone-mascara.js` (sem texto, mas compartilhados com outras telas), que não entraram na guarda.
- **JS externos (PR seguinte):** `objeto-linha-menu.js` e `judicializar-buscar-pasta.js` leem `cobranca_objeto.js`
  (o `catalogo_js` do `objeto/show`); `carteira-filtro-estado.js` lê `cobranca_carteira.js`, entregue no
  `carteira/show` logo antes do script, que roda sem `defer`. Os três entram na guarda, com as teclas nos permitidos.

## 17. Extração do Financeiro da pasta (f6, 21/10)

Bloco `pasta_financeiro` do catálogo, 197 chaves: 92 do Twig (`comum`, `contrato`, `pro_bono`, `honorarios`, `media`,
`relatorio`, `menu_registro`, `registro`, `arquivos`, `pagamentos`, `corpo`) e 105 dos scripts da aba
(`pasta_financeiro.js`, entregue uma vez pelo `_financeiro.html.twig`). Os scripts inline rodam antes do `bj-traducao.js`
(o base o carrega no fim do corpo), então só pedem texto dentro das funções, na hora do uso. Formatos: `|data` na data do
arquivo, `|data_hora` no "Editado em" e `|hora` na hora do registro; o `title` da hora do registro segue `d/m/Y H:i`
fixo (o JS compara o dia dele com o `criadaEm` que o servidor manda nesse formato), e a pílula do dia (`mesesFin`,
`MESES`), o `toLocale*('pt-BR')` e o `R$` do JS ficam para o formato por idioma. Ficou de fora o texto que vem do PHP:
`financeiro.mediaRotulo`, `linha.quando`, `linha.estado`, `linha.historicoDeCorrecoes`, o rótulo de `arquivo_icone`
(e o espelho dele no JS, travado pelo `PastaFinanceiroIconeEspelhoFolhaTest`), o `meta` do upload e os `erro`/`mensagem`
das respostas. O HTML da pasta (primário, quem só vê, lápide e estado vazio) foi comparado antes e depois, sem diferença.

## 15. Cobrança: carteiras, painel, alertas e importação (0d, 21/10)

- **Escopo:** `cobranca/carteira/*` (lista, `_resultado`, `_paginacao`, `show`, `_resultado_casos`, `_acoes_modais`,
  `_campos_config`), `cobranca/dashboard/index`, `cobranca/alertas/index` e `cobranca/importacao/upload` e `preview`.
  Bloco `cobranca_carteira` do catálogo, 251 chaves. `cobranca-carteira-form.js` e `cobranca-soltar-arquivo.js` não têm
  texto: entram na guarda, sem bloco `cobranca_carteira.js` nem `catalogo_js` (o `cobranca-soltar-arquivo.js` só é
  carregado pelo `show` da carteira, então não houve `cobranca_comum`).
- **Sem mudança visível:** o HTML de 21 telas (lista vazia, com dados e com filtro sem resultado; show sem casos, sem
  emissão, cheio com rótulo próprio e documento, busca vazia com e sem rótulo, Estado sem resultado e "vencidos", o
  fragmento do XHR; painel vazio, com dados e de carteira vazia; alertas vazio, com alerta e de carteira vazia; upload,
  prévia, concluída e reimportação) saiu igual. Mudaram só tokens CSRF, quebras de linha dentro dos parágrafos (o
  texto agora vem numa linha só) e o `confirm()` de excluir documento, que passa por `|e('js')` (o texto mostrado é o
  mesmo). Os ramos sem foto (frescor "hoje" e "há 1 dia", paginação numerada, avisos de dívida sem boleto, Nosso Número
  reutilizado, vencimento alterado e sacado divergente) foram conferidos lendo.
- **Formatos:** `atualizadoEm` da lista, `dadosAtualizadosAte`, a emissão por relatório e `carregadoEm` dos documentos
  viraram `|data` (todos com `if` ou nunca nulos). Os `number_format` (percentual do vencido, taxa de recuperação e o
  valor da dívida sem boleto) ficaram como estão e entram como parâmetro já formatado.
- **Frase partida:** as frases com `<strong>` (encargos ao vivo, aviso do upload, avisos da prévia) foram divididas em
  chaves, como no §8. Comparações `x > 0` dentro de atributo viraram `0 < x` (mesmo resultado), porque o `>` cortava a
  tag para a guarda; o filtro do link de exportar foi para um `{% set %}` pelo mesmo motivo. `cs-body` e
  `resources.carteira.gerenciar` (nome de permissão) ficaram nos permitidos.
- **Ficou de fora:** o texto que vem do PHP (rótulos de modo, honorários, juros, multa e vínculo do `CarteiraResumoOutput`,
  a ajuda dos popovers `ajudaCarteira`, as opções de Estado, `statusLabel` dos casos, tipos e descrições dos alertas,
  categorias de documento, motivos de rejeição da importação, rótulos dos Form types e os flashes), o
  `carteira-filtro-estado.js` (o rótulo do chip) e os parciais de outras pastas que estas telas incluem
  (`cobranca/_partials/_subnav`, `_partials/_filtro_barra` e `_filtro_paginacao`).

## 19. Cobrança: pessoa, central e subnav (0d, 21/10)

- **Escopo:** `cobranca/pessoa/show` e os cinco parciais de `pessoa/_partials` (`_ficha_abas`, `_qualificacao`,
  `_lista_enderecos`, `_lista_telefones`, `_lista_emails`), `cobranca/central/*` (`index`, `_aba_atividade`,
  `_detalhe_pessoa`, `_lista_eventos`, `_momento`) e `cobranca/_partials/_subnav`. Bloco `cobranca_pessoa` do catálogo,
  87 chaves, com `cobranca_pessoa.js.*` (3) para o JS inline da Central (entregue por `catalogo_js('cobranca_pessoa.js')`
  antes do `<script>`). A ficha da pessoa não tem JS com texto.
- **Sem mudança visível:** o HTML da ficha completa e vazia (página e fragmento das abas), da Central com eventos, vazia,
  da semana e do período personalizado, e do detalhe da pessoa (com e sem lançamentos de cadastro expandidos, sem
  responsável e vazio) saiu igual. Mudaram só tokens CSRF, quebras de linha, o `<script data-bj-t>` e os literais
  trocados por `BJ_T.t()` no JS inline. O `confirm()` de excluir telefone continua com `|json_encode`, que já escapava.
- **Formatos:** só o `_momento` (chamado sempre sob guarda de nulo) passou a `|data_hora`. O `criadoEm` das listas e o
  `ocorridoEm` dos eventos seguem com `|date` e entram como parâmetro já formatado.
- **Escolhas:** "N lançamento(s) de cadastro/importação" virou duas chaves (`lancamentos_um`, `lancamentos_varios`)
  escolhidas pelo mesmo `== 1` de antes, sem plural ICU. O `id` das abas em construção (`Resultado`, `Pendencias`,
  `Extrato`, identificador do DOM) sai de `'resultado'|capitalize` para não parecer frase ao detector. O enum
  `App\\Cobranca\\Enum\\TipoTelefone` (em `enum_cases`) ficou nos permitidos.
- **Ficou de fora:** o texto do PHP (rótulos dos Form types `AdicionarEnderecoType`, `AdicionarTelefoneType`,
  `AdicionarEmailType` e `EditarQualificacaoPessoaType`, `TipoTelefone::label()`, `tipoLabel` e `descricao` dos eventos,
  rótulos de desfecho e canal, o nome "Sem responsável" da linha órfã, flashes e mensagens das rotas da ficha); o
  `telefone-mascara.js` (sem texto) não entrou na guarda.

## 21. Extração do Auth (9b, 21/10)

- **Escopo:** `templates/auth/` (12 dos 13 arquivos: `meus_convites`, `cadastro/confirmar_email` e `erro`,
  `conta/_reenviar_confirmacao`, `convite/ver`, `ver_logado`, `nao_pertence` e `erro`, `convites/gerenciar`,
  `senha/redefinir` e `erro`, `suporte/mensagens`) e `auth/criar-senha.js` (a régua de força do "Crie sua senha"). Bloco
  `auth` do catálogo, 108 chaves (`comum` 7, `meus_convites` 7, `cadastro` 2, `link` 11, `reenviar_confirmacao` 1,
  `convite` 34, `gerenciar` 21, `senha` 7, `suporte` 14, `js` 4). Os rótulos da força ficam em `auth.js.forca.*`,
  entregues por `{{ catalogo_js('auth.js') }}` no `confirmar_email`, só quando o formulário aparece, antes do script
  (que roda com `defer`, depois do `bj-traducao.js`); o `entrada.js` pode reusar as mesmas chaves no PR dele.
- **Sem mudança visível:** o HTML de 22 respostas (Meus convites vazio e cheio; link de confirmação com motivo, "Crie
  sua senha" com erro, já confirmado e senha criada; erro do cadastro; botão de reenviar; os três erros do convite;
  convite de outra conta; convite do escritório com nome e da plataforma sem nome; convite logado com e sem dados;
  link de senha inválido; Criar nova senha; Gerenciar convites vazio e com as seis situações, com e sem reenvio;
  mensagens de suporte vazia e com e sem nome e assunto) foi comparado antes e depois. Mudou só o
  `<script data-bj-t>` do "Crie sua senha" (e o recuo da linha do script seguinte); tokens CSRF foram ignorados.
- **Formatos:** `expiresAt` do convite e da lista viraram `|data`; a hora do convite, `|hora`; `createdAt` dos convites
  enviados e `criadaEm` das mensagens de suporte, `|data_hora` (nenhum pode ser nulo ali).
- **Frase partida:** "Li e aceito os Termos de uso e a Política de privacidade.", "Ao criar a conta você declara
  ciência da Política de Privacidade.", "Este convite foi enviado para <email>.", "colaborar em <escritório>" e o
  subtítulo das mensagens de suporte (com as aspas retas de "Falar com suporte") foram divididos em chaves, com o
  link, o `<strong>` e as aspas no template, como no §9. Os `confirm()` de reenviar e revogar convite seguem com o
  e-mail fora da chave (`|e('html_attr')`, como antes), só o texto em volta é chave, para o HTML sair idêntico.
- **Guarda:** as comparações com o nome do caso do enum (`situacao.name == 'Pendente'`, `'SenhaCriada'`) passaram a
  `situacao.name|lower == 'pendente'` (mesmo resultado), para o detector não ler o nome do caso como frase. Em
  `permitidos`: `BlueJus` (alt do logo) e os nomes das duas constantes PHP lidas por `constant()`
  (`CadastroController::CSRF_CRIAR_SENHA` e `CriarConviteEscritorioUseCase::RECUSA_EMAIL_A_CONFIRMAR`).
- **Ficou de fora:** o texto que vem do PHP: o `motivo` dos erros (exceções), os flashes, o `erro` do "Crie sua
  senha", a recusa `RECUSA_EMAIL_A_CONFIRMAR` do aviso de e-mail a confirmar, os rótulos e a mensagem de validação do
  `RedefinirSenhaType` e o `nomeExibido` do perfil; a situação crua do convite quando não é uma das cinco conhecidas
  (`convite.status`) e a origem crua da mensagem de suporte (dado). O `_fundo_auth.html.twig` (fora de `auth/`, também
  da tela de entrada) e o `entrada.js` vão com o PR da tela de entrada. O e-mail
  `auth/cadastro/email_confirmacao.html.twig` não entrou: nenhuma área extraiu e-mail até aqui, e o e-mail segue o
  idioma do destinatário, não o da requisição (entrega E do §5, com o locale de quem lê, §4), o que pede o idioma
  gravado no perfil de quem recebe na hora de montar a mensagem; fica para a entrega dos e-mails.

### 21.1 Entrada (registration/ e security/) (9b, 22/10)

- **Escopo:** `security/login.html.twig` (cartão, tarja, segurança e privacidade, modal da senha por código e do
  cadastro, véu da conta nova, rodapé, suporte e as ferramentas SÓ MASTER), `security/entrando.html.twig` (véu depois do
  login), `registration/confirm.html.twig`, `_fundo_auth.html.twig` (sem texto: entra na lista só como guarda) e
  `auth/entrada.js`. Bloco `entrada` do catálogo, 127 chaves (`login` 21, `link` 7, `seguranca` 24, `modal` 17,
  `abrindo` 1, `suporte` 16, `entrando` 4, `registration` 6, `js` 31). A régua de força do `entrada.js` usa as
  `auth.js.forca.*` do §21, sem chave repetida.
- **JS nas páginas sem o base:** o login e o véu não usam o `base.html.twig`, então trazem o próprio
  `{{ catalogo_js(...) }}` e o `bj-traducao.js` (sem defer), como o `mapa_mundo` da P3: o login entrega `entrada.js` e
  `auth.js.forca` antes do `entrada.js` (que roda com defer); o véu entrega `entrada.js.saudacao` antes do script
  inline. A saudação ("Bom dia, Ana!") é a mesma chave nos dois (`com_nome: '{saudacao}, {nome}!'`, `sem_nome`).
  O `LoginTelaTest::testScriptEEstiloProprios` passou a aceitar o bloco `type="application/json"` (dado, o navegador
  não executa), como o `testCsp` do painel na P3.
- **Sem mudança visível:** o HTML do login anônimo, com `?abrir=senha`, com erro de credencial, com a conta a confirmar
  (aviso e botão de reenviar) e do Master logado (links, editor de link, quem recebe o suporte), do véu com nome (com
  `&`, `<b>`, apóstrofo e acento) e sem nome e dos blocos do `registration/confirm` foi comparado antes e depois, com os
  tokens CSRF normalizados: mudaram só o `<script data-bj-t>` e a linha do `bj-traducao.js` (e, no véu, o corpo do
  script inline, que passou a ler o catálogo). Os três modais (senha por código, cadastro, suporte) estão no mesmo HTML
  e entraram na comparação. O texto que o `entrada.js` escreve foi comparado num DOM falso, HEAD contra o novo, em 223
  registros por rodada e em três horas do dia (manhã, tarde e noite): validação do login, olho da senha, os três passos
  da senha com as falhas do servidor e da rede, a régua nas cinco forças, o cadastro com cada recusa, a confirmação do
  e-mail com reenvio, o véu da conta nova, os links do Master (título, editar, mostrar e ocultar, endereço inválido,
  falha e gravação) e o suporte (os quatro assuntos, recusas, falha e envio), iguais letra a letra, incluindo os corpos
  dos POST e sem nenhuma chave faltando. O script inline do véu foi comparado do mesmo jeito, nas três horas, com e sem
  nome.
- **Assuntos do suporte:** são dado, o servidor compara o português (`EnviarMensagemSuporteInput::ASSUNTOS`): o
  `data-assunto` e o corpo do POST continuam o português, e só o rótulo do chip sai do catálogo
  (`entrada.suporte.assunto.*`). O texto que o chip escreve na mensagem passou a ser o rótulo do chip (`textContent`), e
  não o valor; em português é o mesmo.
- **Termo Master:** "SÓ MASTER" e "QUEM RECEBE AS MENSAGENS DE SUPORTE · SÓ MASTER VÊ" recebem `termos.master` por
  `{papel}`, em `|upper`, como no §8.
- **Textos de segurança:** os do desenho 10 que o dono confirmou (tarja, selo do cartão e a janela de segurança e
  privacidade, inclusive "Criptografia de ponta a ponta") entraram com o texto idêntico.
- **Permitidos (8):** `BlueJus` (alt do logo), `https://` (placeholder do editor de link), o nome da classe lida por
  `constant()` (`App\\Auth\\Service\\ContaAConfirmar`), `Não consigo entrar` e `Outro assunto` (os valores de assunto que
  o detector lê; o JS compara o segundo), `Content-Type`, `CapsLock` e `Escape`.
- **Ficou de fora:** o texto que vem do PHP: as quatro frases da tarja (`SecurityController::FRASES`), o erro de login
  (`security` do Symfony e `ContaAConfirmar::mensagemDeLogin`), os textos da `ContaAConfirmar` no passo "Confirme seu
  e-mail", o rótulo dos links do rodapé e o texto de quem recebe o suporte (`PaginaDeEntrada`), os flashes e as
  respostas dos endpoints (`erro`, `mensagem`, `texto`). O assunto do `mailto:` do encarregado
  (`Encarregado de dados (LGPD)`, dentro do `href`) e o `lang="pt-BR"` fixo das duas páginas.
  `registration/index.html.twig` não entrou: é o esqueleto do `make:controller` em inglês ("Hello
  RegistrationController!") e nenhum controller o renderiza (o `registration/confirm` também não tem quem o renderize);
  **para decidir:** apagar os dois.

## 22. Extração do Kanban e do que faltava em Tarefa (f6, 21/10)

Bloco `kanban` do catálogo, 70 chaves: 56 do Twig (`comum`, `index`, `modal_criar`, `mural`, `modal_checklist`,
`modal_editar`, `card`, `comentario`) e 14 dos scripts inline do `index` e do `board` (`kanban.js`, entregue pelos
dois antes do `<script>`; o corpo do card, que chega por fetch, usa o catálogo do `board`). O `tarefa/show` entrou no
bloco `metas` (o do Minhas Metas): 43 chaves em `metas.detalhe` (Twig) e 8 em `metas.detalhe.js` (fora de `metas.js`,
para o catálogo que o Minhas Metas entrega não mudar), com `metas.migalha.home` e `metas.comum.nao_informado`
reaproveitadas; os scripts dele ficam no corpo (antes do `bj-traducao.js`) e só pedem texto dentro dos eventos. O `#`
da meta entra como texto (`tarefa.id ~ ''`), para o ICU não pôr separador de milhar; a contagem de cards do mural
virou duas chaves (`cards_um`, `cards_varios`) escolhidas pelo mesmo `!= 1` de antes. Os dois `confirm()` dos
formulários do detalhe usam `|trans|e('js')`, como na Agenda. Para a guarda enxergar as tags, os `filter(x =>
...)|length > 0` de dentro dos atributos foram para um `{% set %}` antes da tag. Formatos: `|data` na criação do
mural, na criação e no prazo da meta e no dia do histórico; `|data_hora` no comentário, na criação do card, na
conclusão e na alteração da meta; `|hora` na hora do histórico. Ficaram `dataVencimento|date('d/m')` do cartão (sem
filtro de dia e mês) e os `Y-m-d` dos campos de data. Ficou de fora o texto que vem do PHP: os rótulos dos campos dos
Form types do mural (`form_row`), `tamanhoFormatado` do anexo, as respostas e erros dos controllers do Kanban,
`Tarefa::STATUS_LABELS`, `prazoInfo.texto`, o título e o detalhe dos eventos do histórico (`TarefaTimelineAssembler`) e os
flashes; também as mensagens de `console.error` do `board` (só para quem depura). O HTML do Kanban (lista vazia e
cheia, mural do criador e do participante, card cheio, magro e de mural sem marcadores, cartão do mural e comentário
novo) e do detalhe da meta (vazia, nova, pendente com anexo, em revisão para os dois lados, concluída) foi comparado
antes e depois, sem diferença; o texto dos scripts foi conferido chave por chave contra o literal removido.

### 22.2. Extração da Inteligência (f6, 21/10)

- **Escopo:** os 12 templates de `templates/inteligencia/` (drawer, painel, seção do comando, índice de documentos,
  cartão e listas das análises, análises do Push, conversa e rodapé, auditoria e `admin/configuracao`) e os três JS
  (`pasta-ia-agentes.js`, `pasta-ia-conversa.js`, `pasta-ia-push.js`). Bloco `inteligencia` do catálogo, 199 chaves: 153
  do Twig (`comum`, `drawer`, `painel`, `comando`, `documentos`, `cartao`, `lista`, `push`, `conversa`, `auditoria`,
  `admin`) e 46 dos scripts (`js.agentes`, `js.conversa`, `js.push`). O drawer entrega
  `catalogo_js('inteligencia.js.agentes', 'inteligencia.js.conversa')` uma vez, antes dos dois scripts (ele só existe
  no cabeçalho da pasta); o `_push_processual` entrega `inteligencia.js.push` junto do `pasta-ia-push.js`. Os scripts
  são `defer` e só pedem texto na hora do uso (a constante `FALHA_AO_ENVIAR` da conversa virou função).
- **Sem mudança visível:** o HTML de 46 respostas (pasta, painel, listas por comando, relatório, índice e agente,
  Push, conversa, auditoria e configuração, nos estados vazio, sem provedor, cheio com concluída, lida, interna,
  editada, recolhida, falha com motivo técnico, em andamento, conversa com resposta, falha e pergunta em andamento,
  download e impressão na trilha, leitor sem edição nem Financeiro e aba Documentos oculta) foi gravado com os
  templates da HEAD e com os novos na mesma execução e comparado com tokens normalizados: igual, fora o
  `<script data-bj-t>`. O texto dos scripts foi conferido chave por chave contra o literal removido. A nota de edição
  do Push é uma chave só, com a frase inteira e `{editado}`; as aspas saem como `&quot;` no HTML, iguais na tela.
  Ficam partidas, sem como reordenar por idioma, as listas de envio do admin (`envio.e`, por causa dos
  `<span data-ia-envio>`) e o `uso_hoje`/`uso_mes` (número fora da chave).
- **Formatos:** dia e hora do aviso dos cartões viraram `|data` e `|hora`; o "quando" do Criar tarefa e o aceite da
  configuração, `|data_hora`; a hora do "Editado em", `|hora` (nenhum nulo ali). Ficaram os `|date('d/m')` (marca do
  dia, "Editado em" e trilha `d/m H:i`): não há filtro de dia e mês. Contagens entram como texto (`~ ''`); "item/itens"
  e "movimentação/movimentações" viraram duas chaves pela mesma condição `== 1`; "ponto(s)" e "movimentação(ões)"
  ficam como estão.
- **Ficou de fora (vem do PHP):** a mensagem da `Disponibilidade` (`ia.mensagem`), rótulo, ícone e descrição de
  `Comando`, nome e seções de `Agente` (`rotuloCurto`), os itens da grade "O que a IA sabe" (`rotulo`, `corte`), os
  dados de documento (`dados`), o rótulo dos pontos, `statusRotulo` e `oQue` da auditoria, o `quando` e a `dica` do
  envio ao cliente e o texto do `avisar_cliente()`, os rótulos e erros do `ConfiguracaoType`, os flashes e as
  respostas JSON (`mensagem`, `aviso`) dos controllers e UseCases, o texto do `.txt` exportado e todo prompt. "BlueJus
  IA" e "BLUEJUS IA" (marca) e o nome do enum lido por `enum_cases()` estão nos `permitidos`.

## 23. Extração de Feriados e da conexão do Drive (4b, 21/10)

- **Escopo:** `templates/feriado/index`, `new`, `edit` e `_form` (Feriados e Folgas do escritório) e
  `templates/sync/drive_conexao` (Conectar meu Drive). Nenhuma das telas tem JS próprio. Blocos `feriado` (23 chaves)
  e `sync` (18 chaves, em `sync.drive.*`), os dois antes do `# ---- fim`; áreas `feriado` e `sync` no
  `templates-extraidos.json`, com o exemplo de link do Drive (`placeholder`) nos permitidos. As telas de `processo/` e
  de `djen/` vêm em PRs seguintes desta mesma §23.
- **Sem mudança visível:** o HTML de 9 telas (Feriados vazio, com feriado recorrente e pontual e depois de excluir;
  Novo feriado, Novo com erro de validação e Editar, com nome com apóstrofo, aspas, `&` e tag; Conectar meu Drive sem
  conexão, conectada sem e-mail e sem pasta-raiz, e ativa) foi gravado antes e depois e comparado com tokens e ids
  normalizados: saiu igual. Mudaram só a quebra de linha dentro de três parágrafos (o texto de duas linhas virou uma
  chave de uma linha; o navegador mostra igual) e os dois `confirm()` dos `onsubmit`, agora `|trans|e('js')` (o mesmo
  texto, escapado para JS). O nome do feriado entra no confirm por `{nome}`, com o apóstrofo do ICU escrito `''`.
- **Formatos:** a data do feriado pontual (`|date('d/m/Y')`) virou `|data` e o "Conectado em" (`|date('d/m/Y H:i')`),
  `|data_hora` (a conexão sempre tem a data, então o `|data_hora` de nulo não muda nada). Ficou o `|date('d/m')` do
  feriado recorrente: não há filtro de dia e mês (o mesmo caso do dd/mm do próximo feriado da §18).
- **Ficou para depois:** os rótulos, o placeholder e a mensagem de validação do `FeriadoType` (Nome, Data, "Repete todo
  ano (feriado fixo)", "O nome é obrigatório."); os flashes do `FeriadoController` e do `DriveConexaoController`
  (inclusive a mensagem de exceção do `DefinirRootFolderDriveUseCase` repassada em flash) e as mensagens dos 403/404,
  como na §18; o `|date('d/m')` acima.

### 23b. Processo (4b, 21/10)

- **Escopo:** `templates/processo/index`, `_resultado`, `new` (novo e editar), `show`, `_notas_tecnicas` e
  `_nota_tecnica_item` (as notas técnicas aparecem também na aba Processo e no teor do Push da pasta, e o cartão volta
  pronto do `processo_nota_tecnica_criar`). Nenhum JS em `public/js` é só destas telas (o `nota-tecnica.js` é carregado
  pela pasta); o script inline do `new` passou a ler `processo.form.js.*` pelo `catalogo_js`, e o texto do catálogo que
  entra no HTML montado passa por um `esc()` local. Bloco `processo` com 190 chaves (`comum` 23, `situacao` 7, `index`
  7, `resultado` 7, `show` 44, `form` 73, dos quais 30 em `form.js`, `notas` 11, `nota` 18), logo depois do
  `pasta_processo`; área `processo` no `templates-extraidos.json`, sem permitidos. Nenhuma chave `pasta_processo.*` foi
  reaproveitada: o domínio Processo tem o próprio bloco, e as notas técnicas (que a §11 deixou de fora) entram aqui.
- **Sem mudança visível:** o HTML de 12 situações (lista vazia, com processos e ordenada; Novo; Editar com partes,
  movimentações e assuntos e Editar vazio; detalhe completo, com tarefa, pasta principal e lápide, e detalhe vazio; a
  pasta com notas técnicas próprias, alheias, antigas, editadas e de movimentação do Push; a pasta lápide; o cartão
  devolvido ao criar uma nota), com dados que têm apóstrofo, aspas, `&` e `<b>`, foi gravado antes e depois e
  comparado com tokens, ids e dígitos normalizados; o script inline do `new` foi comparado com as chamadas ao catálogo
  trocadas pelo texto que a página entrega. Saiu igual. Mudou só o `confirm()` do excluir processo, agora
  `|trans|e('js')` (o mesmo texto, escapado para JS). A conta `length > 1` dos botões Remover saiu do atributo `style`
  para um `{% set %}` antes do laço (o detector lia o `>` como fim de tag); o valor é o mesmo.
- **Formatos:** no detalhe, datas viraram `|data` (distribuição, baixa, movimentação, prazo da tarefa) e `|data_hora`
  (última atualização, criado, modificado, tarefa, exclusão da pasta), todas já com guarda de nulo ou com data sempre
  presente; na nota técnica, `|data_hora` no `title` e no "Editada em", `hoje, {hora}` e `{data}, {hora}` com `|data`
  e `|hora`. O total da lista, os minutos da janela da nota e o tempo da tarefa entram como texto (`~ ''`) para o ICU
  não pôr separador de milhar. Ficaram os `|date('Y-m-d')` dos campos `type="date"` (valor do campo, não texto) e o da
  comparação "é hoje".
- **Ficou para depois:** a situação crua do processo e da tarefa (`situacaoProcesso|replace`, `statusAtual|upper` e o
  mesmo no modal), a situação da pasta (`|capitalize`), o "-" de vazio e o nome do tipo da parte (`parte.tipo`), que são
  dado; os flashes e as recusas do `ProcessoController` e do `NotaTecnicaController` e o `error` que o
  `api_datajud_search` devolve ao modal; o `nota-tecnica.js` (área da pasta); o `aria-label="Close"` do modal de
  mensagem, que continua em inglês como estava (agora numa chave).

### 23c. DJEN (4b, 21/10)

- **Escopo:** `templates/djen/index` (Push Processual: Publicações), `oabs` (OABs monitoradas) e `show` (a
  publicação). Nenhum JS em `public/js` é destas telas, e elas não têm script inline. Bloco `djen` com 65 chaves
  (`comum` 10, `index` 26, `oabs` 15, `show` 14), logo antes do `feriado`; área `djen` no `templates-extraidos.json`,
  sem permitidos. Não havia chave `djen.*` no catálogo para reaproveitar (o `pasta_push` é da aba Push da pasta).
- **Sem mudança visível:** o HTML de 9 situações (publicações vazia, com 28 publicações nas páginas 1 e 2, com todos os
  filtros preenchidos e com o filtro de vinculadas; OABs vazia e com uma ativa e uma pausada; a publicação completa,
  avulsa, com destinatários, link, teor e OAB, e a vinculada com campos vazios e `?voltar=`), com dados que têm
  apóstrofo, aspas, `&` e `<b>`, foi gravado antes e depois e comparado com tokens, nomes gerados e dígitos
  normalizados: saiu igual. Mudaram só as aspas do texto de lista vazia, agora `&quot;` (o navegador mostra igual), e o
  `confirm()` do remover OAB, agora `|trans|e('js')` (o mesmo texto, escapado para JS). O total e as páginas da
  paginação entram como texto (`~ ''`) para o ICU não pôr separador de milhar.
- **Formatos:** nenhum filtro de data nestas telas: a data de disponibilização e o "Desde" da OAB chegam já formatados
  (`d/m/Y`) pelos DTOs `PublicacaoDjenListaItem`, `PublicacaoDjenOutput` e `OabMonitoradaOutput`.
- **Ficou para depois:** as datas formatadas em PHP nos DTOs acima (passar a data e usar `|data` na tela muda o
  contrato do DTO); o rótulo do meio (`Diário Eletrônico`, `Edital`) e do polo (`Ativo`, `Passivo`, `Terceiro
  interessado`) montados no `PublicacaoDjenOutput`; os rótulos, placeholders e mensagens do `OabMonitoradaType` e do
  `OabMonitoradaInput`; os flashes do `DjenController` (inclusive o resumo da sincronização e as mensagens de exceção
  dos UseCases repassadas em flash) e as mensagens dos 404, como na §18.

## 24. Extração dos parciais comuns, da raiz e das telas avulsas (7d, 21/10)

### 24.1 Parciais comuns (_partials)

- **Escopo:** `_partials/_avatar_usuario` (sem texto: só as iniciais, que são dado), `_filtro_barra`,
  `_filtro_paginacao`, `_janela_confirmacao`, `modal_access_request`, `modal_mover_marcador` e `modal_nova_pasta`, com os
  scripts inline deles, mais `filtro-tabela.js` (motor do filtro de Processos, Demandas, Cobrança, Dashboard e Minhas
  Metas) e `pasta-dialogos.js` (a janela de confirmação, com os responsáveis e o calendário da Nova meta). Bloco `comum`
  do catálogo, 71 chaves, 45 delas em `comum.js.*`. O `base.html.twig` passou a entregar `comum.js` no mesmo
  `catalogo_js` da moldura, para o JS ter o texto em qualquer tela que inclua um parcial.
- **A interface dos parciais não mudou:** os mesmos parâmetros do `with {...}`. O placeholder padrão do `_filtro_barra`
  (`Buscar…`) virou `comum.filtro.buscar`, mas só vale quando o incluidor não passa `busca_placeholder` (hoje todos passam).
  O "Filtrar por X" recebe o rótulo da faceta por parâmetro. Na Nova Pasta do desenho 1.1 os rótulos em caixa alta são
  as mesmas chaves da variante antiga com `|upper`.
- **Ordem de carga:** os scripts inline dos parciais rodam no `body`, antes do `bj-traducao.js` do base; por isso só
  chamam `BJ_T.t` em evento, `DOMContentLoaded` ou resposta de `fetch` (o pedido de acesso guarda as chaves das ações e
  dos recursos, `termos.pasta` e `termos.processo`, e lê o texto na hora). Os nomes dos meses do calendário passaram a
  ser chaves inteiras (`comum.js.dialogos.mes.*`), lidas ao desenhar. O `aria-label` do botão de fechar do aviso do
  pedido de acesso entra por `setAttribute` depois da inserção, sem texto do catálogo dentro de HTML.
- **Sem mudança visível:** o HTML de 16 respostas (Expediente; acervo nas páginas 1 e 2 com 55 pastas; Pasta com
  marcadores; Processos com 27 processos, página 2, busca com faceta e busca vazia; Minhas Metas; Demandas nas páginas
  1 e 2; Casos e Carteiras da Cobrança; Dashboard; `/pasta`, que redireciona) foi comparado antes e depois. Sobraram só
  o token CSRF do pedido de acesso, a cor do avatar do Dashboard (calculada pelo id do usuário, que muda entre as
  rodadas) e as linhas de JS que agora leem o catálogo.
- **Formatos:** no `pasta-dialogos.js`, o dia escolhido no calendário (`toLocaleDateString('pt-BR')`) virou
  `BJFormato.data`. Ficaram: o rótulo longo do dia (`weekday: 'long'`, sem equivalente no `BJFormato`) e o
  `formatarData` do `filtro-tabela.js`, que monta dd/mm/aaaa à mão para o chip de data.
- **Guarda:** nos permitidos ficaram o pedaço do `_filtro_paginacao` que o detector lê como texto (o `<=` e o `>=` da
  expressão Twig parecem uma tag), a constante `CoresDoMarcador::DESENHO` e os nomes de tecla (`Enter`, `Escape`,
  `ArrowDown`). `PastaDialogosJsTest` e `PastaConfirmacaoDoDesenhoJsTest` conferem agora a chave no código e o texto
  exato no catálogo.
- **Ficou de fora:** as mensagens que vêm do servidor (`data.error`/`data.message` do pedido de acesso, `json.mensagem`
  da Nova Pasta, `erro`/`mensagem` dos marcadores) e o HTML do aviso de pasta parecida, que o `PastaController` monta.
  O aviso do pedido de acesso continua pondo a mensagem do servidor em `innerHTML`, como antes (não é texto do catálogo).

### 24.2 Templates da raiz (7d, 21/10)

- **Escopo:** os quatro templates soltos na raiz de `templates/`: `_fundo_auth` (fundo do login e da redefinição de
  senha), `_oab_status` (chip de status da OAB, usado na fila de OAB do Master), `_recurso_faixa_master` (faixa do
  Master numa tela desligada, incluída pelo base, pelo `layout_peticionar` e pelo `push_compartilhado/show`) e
  `layout_peticionar` (o layout próprio do Peticionar, que não herda o base). 15 chaves novas: `oab_status` (4),
  `peticionar` (10, em `peticionar.cabecalho.*` e `peticionar.processo.*`, antes do `# ---- termos`) e
  `moldura.faixa_master.aviso` (1, dentro do bloco `moldura`, sem repetir a chave de topo). O cabeçalho do Peticionar
  repete o do base e reaproveita 9 chaves que já existiam com o mesmo rótulo e sentido (`moldura.notificacoes.rotulo`,
  `contagem_sufixo`, `nenhuma`, `ver_todas`; `moldura.cabecalho.tela_cheia` e `menu_usuario`;
  `menu.usuario.dropdown.meu_perfil`, `privacidade` e `sair`). O `_fundo_auth` só tem CSS e comentário: nenhuma
  chave, mas entra na lista para o guarda pegar texto novo. Área `raiz` no `templates-extraidos.json`, com "BlueJus"
  e o "| BlueJus" do `<title>` do Peticionar nos permitidos. Nenhum dos quatro tem script inline, então o
  `layout_peticionar` não precisou do `catalogo_js` nem do `bj-traducao.js`.
- **Sem mudança visível:** o HTML de 10 telas (redefinir senha com token válido e com token inválido; a fila de OAB do
  Master com inscrições confirmada, divergente e não verificada, nos filtros "todas" e padrão, e vazia; Minhas Metas
  como Master com a tela ligada e desligada, isto é, com a faixa; o link público do Push com a tela `pushpub`
  desligada, com a faixa; o Peticionar de uma pasta vazia e de uma com cliente, nome da ação e processo com partes e
  todos os campos do cartão) foi gravado antes e depois e comparado com tokens e ids normalizados: 7 telas saíram
  iguais e as outras 3 diferem só nos ids das inscrições (nos `action` dos formulários), no token CSRF e no minuto da
  marca-d'água do link público (o relógio).
- **Formatos:** a data de distribuição do cartão do processo (`|date('d/m/Y')`) virou `|data` (só aparece quando há
  data, então o `|date(null)` não entra em jogo).
  O "X" entre os polos do processo também virou chave (`peticionar.processo.contra`).
- **Ficou para depois:** o estado "Sem OAB" do chip não apareceu na foto (a fila só lista inscrições, que sempre têm
  status); a chave existe e a troca é a mesma das outras três. O `pasta/peticionar.html.twig` (o conteúdo do Peticionar) e os outros templates que
  incluem estes quatro ficam para a extração das áreas deles.

### 24.3 Telas avulsas: notificações, novidades, pedidos de acesso e auditoria (7d, 21/10)

- **Escopo:** `notificacao/index` (Central de notificações, com o script inline da seleção, do marcar como lida e da
  exclusão), `novidades/index` (só a moldura: o texto das versões vem do `config/novidades.yaml`, é dado e não passa
  pelo catálogo), `access_request/index` (Solicitações de Acesso Pendentes, com o modal de aprovação) e `audit/index`
  (Trilha de Auditoria, com o script inline do select2). Nenhuma das quatro carrega JS próprio em arquivo. Blocos
  `notificacao` (22 chaves, 3 em `notificacao.js.*`), `novidades` (4), `access_request` (19) e `auditoria` (39, 1 em
  `auditoria.js.*`), 84 ao todo, antes do `# ---- termos` (não havia bloco de auditoria no catálogo). "Excluir" e
  "Cancelar" reaproveitam `comum.excluir` e `comum.cancelar`. Área `avulsas` no `templates-extraidos.json`.
- **Scripts inline:** cada tela entrega `{{ catalogo_js('<bloco>.js') }}` logo antes do `<script>`; o texto só é lido em
  evento ou no `ready`. O "Lida" que troca a coluna depois de marcar como lida deixou o `innerHTML` e é montado com
  `createElement`/`textContent` (o tempo relativo entra por `textContent`).
- **Auditoria:** as frases dos eventos de associação têm a referência da entidade (macro `entityRef`, rótulo ou
  "Classe #id") no template, em volta dos pedaços `auditoria.evento.*` (`foi removido de {campo} de`...): passar a saída
  da macro como parâmetro do `|trans` escaparia o rótulo duas vezes. O "Entidade" de reserva da macro virou chave.
- **Sem mudança visível:** o HTML de 12 respostas (notificações vazia, só pessoais com lida e não lida, com as abas
  Minhas e Gestão, a aba Gestão aberta e a página 2; Novidades; pedidos de acesso vazio e com três pendentes, nome com
  apóstrofo e aspas, descrição longa, curta e vazia, mais dois decididos que não aparecem; auditoria vazia por filtro,
  cheia com update, create, delete, sem diff, ação desconhecida, eventos de associação, rota e IP, filtrada por
  entidade, usuário e data, e a página 2 com 61 registros) foi comparado antes e depois com tokens e ids normalizados.
  Sobraram só as linhas de JS que agora leem o catálogo, a montagem do "Lida" acima e os `confirm()` dos `onsubmit`
  (Negar o acesso e Desfazer), agora `|trans|e('js')`: o mesmo texto, escapado para JS. No Negar isso conserta de
  passagem um defeito: o nome do solicitante entrava só com escape de HTML e um apóstrofo no nome quebrava o JS do
  `confirm` (o formulário seguia sem perguntar); agora o nome entra por `{nome}` e escapado.
- **Formatos:** a data da versão nas Novidades (`|date('d/m/Y')`) virou `|data` e o "Solicitado em" (`|date('d/m/Y
  H:i')`), `|data_hora` (os dois sempre têm valor). Ficou o `|date('d/m/Y H:i:s')` da Data/Hora da auditoria: não há
  filtro com segundos.
- **Guarda:** nos permitidos ficaram o "| BlueJus" do título das Novidades, os pedaços das expressões de paginação que
  o detector lê como texto, os códigos de ação `view`/`edit`/`delete` dos selos do modal, o `true`/`false` com que a
  auditoria mostra valores booleanos e as classes `text-bg-*` da macro do selo de ação.
- **Ficou para depois:** o texto que vem do PHP: o título, a mensagem e o tempo relativo da notificação
  (`Notificacao::getTempoRelativo`), `data.error` da exclusão, os flashes do `AccessRequestController` e do desfazer da
  auditoria, as mensagens dos 403; o tipo de recurso e a ação do pedido (`req.resourceType`, `req.action`) e a ação da
  auditoria (`log.action|upper`), que saem como o código gravado; os rótulos das opções de entidade e usuário dos
  filtros (montados no `AuditLogController`); e o `|date` com segundos.

## 20. O resto da Pasta (0d, 21/10)

### 20.1 Avisos, cadeado e telas simples

- **Escopo:** os parciais `pasta/_abas_ocultas`, `_andamento`, `_aviso_duplicada_conteudo`, `_aviso_duplicada_modal`,
  `_cadeado_faixa`, `_cadeado_icone` (sem texto), `_cadeado_menu`, `_cadeado_modal` e `_cadeado_pedidos`, as páginas
  `confirmar_duplicada`, `trancada` e `zip_recusado`, e os JS `pasta-abas-ocultas.js`, `pasta-andamento.js`,
  `pasta-cadeado.js` e `pasta-cadeado-pedidos.js`. Bloco de topo `pasta_avisos` do catálogo, 93 chaves, com
  `pasta_avisos.js.*` (24) para os quatro JS.
- **Catálogo do JS:** os quatro scripts são carregados pelo `pasta/show.html.twig`, sem `defer`. O
  `catalogo_js('pasta_avisos.js')` mora no `_abas_ocultas`, que o show inclui sempre (fora de condição) e antes dos
  scripts; o show não foi tocado.
- **Sem mudança visível:** o HTML da tela trancada (com o pedido e aguardando), da faixa de somente visualização (pedir
  e pedido enviado), da pasta vista pelo primário (menu e modal do cadeado com e sem cadeado, pedidos pendentes, abas
  ocultas e andamento com a faixa de sugestão), do aviso de duplicada (página e modal, uma pasta e seis com ação) e do
  zip recusado saiu igual. Mudaram só tokens CSRF, quebras de linha (o "Encontramos N pasta(s) já cadastrada(s)" virou
  uma linha) e o `<script data-bj-t>`. Não foram renderizados, e foram conferidos lendo: "Sem registro" (pasta sem data
  de criação), "Não há colaboradores…" (equipe vazia no modal) e "Cliente não informado" na tela trancada.
- **Formatos:** o `criadoEm` do aviso de duplicada (sob guarda de nulo) passou a `|data`.
- **Escolhas:** "N pasta(s) já cadastrada(s)" virou duas chaves (`encontramos_uma`, `encontramos_varias`) pelo mesmo
  `== 1` de antes, sem plural ICU; a frase com `<strong>` ficou uma chave por pedaço (`para`, `em`). O "PASTA" da tela
  trancada usa `termos.pasta|upper` e o selo "OCULTA", `selo_oculta|upper`. Os rótulos dos níveis do cadeado e dos
  botões de pedido viraram chaves dentro dos arrays do Twig, traduzidas no uso. O "fase(s) intermediária(s)" do JS
  continua com "(s)", como no §8. Ficaram nos permitidos a classe `ps-body`, a marca em "· BlueJus" e as teclas e
  cabeçalhos HTTP dos JS.
- **Ficou de fora:** o texto do PHP (rótulo, dica, dia, intervalo e total das fases e o motivo das sugestões do
  `AndamentoDaPasta`; selo, `texto` dos pedidos, `horaDoPedido` e `recusadoTexto` do `CadeadoNaTelaOutput`; o
  `documento_br_rotulo`; a mensagem do zip recusado; a `funcao` da equipe e as mensagens JSON dos controllers do cadeado,
  das abas ocultas e do andamento); o `data-screen-label` do andamento (marca do desenho, ninguém lê); e o
  `lang="pt-BR"` fixo do `zip_recusado`.

### 20.2 Lista de pastas e demandas

- **Escopo:** `pasta/index`, `_tabela`, `_card`, `_cliente_linha`, `_filtros`, `_filtro_situacao`, `demandas` e
  `demandas/_resultado`. Bloco de topo `pasta_lista` do catálogo, 108 chaves, com `pasta_lista.js.*` (5) para o script
  inline da estrela de favorito do `_tabela`. Nenhum JS de `public/js` entrou: não existe `pasta-lista*` nem
  `pasta-filtros*`, e o único JS das demandas, o `filtro-tabela.js`, serve também o painel, a cobrança e o dashboard.
- **Catálogo do JS:** o `catalogo_js('pasta_lista.js')` fica no próprio `_tabela`, logo antes do `<script>` que usa as
  chaves.
- **Onde aparece:** o `_filtros` e o `_filtro_situacao` são do acervo do Expediente (`_acervo_geral` e
  `_painel_marcador`); o `_cliente_linha`, do trilho da pasta e da resposta do vincular/cadastrar cliente. O
  `pasta/index` não é renderizado por rota nenhuma (o `pasta_index` redireciona ao Expediente), e com ele o `_tabela` e
  o `_card`: foram extraídos assim mesmo, porque estão no lote.
- **Sem mudança visível:** o HTML do acervo (vazio, com dados e com filtros), das demandas (vazia, vazia com filtro, com
  dados e o fragmento do XHR), das linhas de cliente da pasta (principal com cadastro completo e segundo cliente com
  pendência e CPF duplicado) e do `_tabela`, `_card` e `_filtros` renderizados direto pelo Twig (com e sem paginação,
  vazio com e sem filtro, pasta favorita, excluída, arquivada e cancelada) saiu igual. Mudaram só tokens CSRF, quebras de
  linha, o `<script data-bj-t>` e a forma do `aria-label` do "Abrir cliente", que passou a ser escapado inteiro pelo
  `html_attr` (o espaço sai como `&#x20;`, mesmo texto). Não foram renderizados, e foram conferidos lendo: "Sem
  registro" das demandas (pasta sem data de abertura), "Cadastro completo" e "Cadastro incompleto" com uma pendência
  só, "Total: 1 pasta" e "N pastas" sem paginação, e "Excluída em" sem quem excluiu.
- **Formatos:** o `excluidaEm` do selo "Excluída" (sob guarda de nulo) passou a `|data_hora` no `_tabela` e no `_card`.
  Os `number_format` do resumo da tabela ficaram como estavam.
- **Escolhas:** "N pasta(s)" do resumo e "N pendência(s)" do cadastro viraram duas chaves cada (`_uma`, `_varias`)
  pelo mesmo teste de antes; "Excluída em … por …" virou duas chaves, com e sem quem excluiu; a dica da estrela do
  principal, duas (com e sem "Clique na estrela de outro para trocar"). O "meta(s) · ativa(s)" e o "demanda(s)
  encontrada(s)" continuam com "(s)", como no §8. Os `map(x => ...)` que estavam dentro de atributos foram para um
  `{% set %}` antes da tag, e o `x > 0` em atributo virou `0 < x`. "CPF" e "CNPJ" seguem como sigla, parâmetro do
  selo de duplicado. Ficou nos permitidos só o `aria-sort=""` do macro de ordenação, como no Expediente.
- **Ficou de fora:** o texto do PHP (`prioridadeLabel`, `situacaoRotulo`, `identificador_pasta`, `documento_br_rotulo`,
  as pendências do `cliente_pendencias` e as mensagens JSON da estrela de favorito e do alternar situação); os parciais
  compartilhados `_partials/_filtro_barra`, `_partials/_filtro_paginacao` e `expediente/_paginacao`; e o
  `filtro-tabela.js`.

### 20.3 Responsável, histórico, impressão e relatórios (4b, 21/10)

- **Escopo:** os parciais `pasta/_resp_chip`, `_resp_estilo` (sem texto), `_resp_menu` e `_resp_script` (o chip e o menu
  de responsável, que servem também ao Expediente), `_historico_drawer`, `_impressao_modal`, `_timeline_inteligente` e
  `_pontos_relatorio`, a folha `resumo_impressao` (modos página, fragmento e PDF) e os JS `pasta-impressao.js` e
  `pasta-timeline.js`, com os scripts inline do `_resp_script` e do `_pontos_relatorio`. Bloco de topo `pasta_resp` do
  catálogo, 145 chaves, 49 delas em `pasta_resp.js.*` (`resp` 5, `pontos` 2, `impressao` 12, `timeline` 30); área
  `pasta_resp` no `templates-extraidos.json`.
- **Catálogo do JS:** cada parcial entrega o sub-bloco do próprio script, antes dele: `_resp_script`
  (`pasta_resp.js.resp`, vale para a pasta e para o Expediente), `_pontos_relatorio` (`pasta_resp.js.pontos`),
  `_impressao_modal` (`pasta_resp.js.impressao`, é ele que carrega o `pasta-impressao.js`) e `_timeline_inteligente`
  (`pasta_resp.js.timeline`; o show inclui o parcial sempre, antes do `pasta-timeline.js`). O show não foi tocado. Os
  scripts inline só chamam `BJ_T.t` em clique ou depois de `fetch`, quando o `bj-traducao.js` do base já carregou; o
  "Sem responsável" que o `_resp_script` monta em HTML passa pelo `escResp()` dele.
- **Sem mudança visível:** o HTML de 51 situações foi gravado antes e depois e comparado com tokens CSRF, dígitos, NUP,
  nome do escritório e espaços normalizados: chip com e sem responsável na pasta e no Expediente, menu nos dois
  contextos, histórico vazio, com um e com vários eventos (hoje, outro dia, meta com e sem link, detalhe com quebra de
  linha), janela Imprimir, timeline com e sem cliente e processo, pontos do relatório vazio, parcial e completo com e
  sem permissão de escrever, a folha nos três modos (resumo cheio e vazio, sem as partes opcionais, observação com
  autor e sem autor, processo, extrato e moldura) e as páginas da pasta (com e sem responsável), do resumo e dos fragmentos,
  com nome de pessoa e cliente com apóstrofo, aspas, `&` e `<b>`. Saiu igual. Mudaram só o `<script data-bj-t>` e as
  linhas de JS que agora leem o catálogo (as chamadas simples foram comparadas trocadas pelo texto que a página
  entrega). O `"` do aviso "Salvar como PDF" sai agora como `&quot;` (o navegador mostra igual). As contas `>` do
  atraso das metas e o `map(f => f.rot)` dos pontos saíram para um `{% set %}` (o detector lia o `>` como fim de
  tag); o valor é o mesmo.
- **Formatos:** no histórico, o dia de outra data (`|date('d/m/Y')`) virou `|data` e a hora, `|hora`; na linha do
  autor da observação impressa, `d/m/Y, H:i` virou `{{ |data }}, {{ |hora }}`. O "11,5 pt" da janela vem de
  `11.5|numero(1)` e, no JS, de `toLocaleString(BJFormato.idioma())` (antes um `replace('.', ',')`). Na timeline, a data
  futura passou a `BJFormato.data`, e o rótulo com dia da semana, o "desde" e o "Alterado em" usam
  `BJFormato.idioma()` no lugar do `'pt-BR'` fixo, com as mesmas opções. Totais e contagens entram como texto
  (`~ ''`) para o ICU não pôr separador de milhar.
- **Escolhas:** "1 alteração" e "N alterações" viraram duas chaves (`alteracoes_uma`, `alteracoes_varias`) pelo mesmo
  `== 1`, sem plural ICU; "acontecimento(s)" da timeline continua com "(s)", como no §8. "Impresso em … por …" virou
  duas chaves, com e sem quem imprimiu. O "· principal" e o "<strong>… recebidos de <strong>…" do extrato ficaram com
  uma chave por pedaço. Ficaram nos permitidos a marca (`BLUEJUS` da folha e o título `BlueJus` do iframe de
  impressão), a constante `PastaFinanceiroOutput::SEM_DADO` e os nomes de tecla e cabeçalho HTTP dos JS (`Accept`,
  `Escape`, `AbortError`). `PastaImpressaoJsTest`, `PastaTimelineArranjoTelaTest`, `PastaRespMenuDesenhoTelaTest` e
  `PastaJanelasRestantesFolhaTest` conferem agora a chave no código e o texto exato no catálogo.
- **Ficou para depois:** o texto do PHP (selo, rótulos e dicas do `PontosDoRelatorioInicial` e a pendência da aba que
  ele devolve; título, detalhe e autor dos eventos do histórico; categorias, prioridades, resumos e `rotuloLink` da
  timeline, que vêm do `pasta_timeline`; o `titulo`, a situação e os valores da folha impressa, montados pelo
  `PastaResumoController` e pelos DTOs; a mensagem de erro do salvar responsável); o "Hoje, dd/mm" do histórico e o
  `d/m/Y, H:i:s` do "Impresso em" (não há filtro de dia e mês nem de hora com segundos); o `lang="pt-BR"` fixo da folha
  e do `srcdoc` do iframe de impressão; o `documento_br_rotulo`.

### 20.4 Documentos, metas e anotações

- **Escopo:** os parciais `pasta/_documentos_checklist`, `_documentos_explorador`, `_documentos_sugeridos`, `_metas` e
  `_dados_anotacoes`, e os JS `pasta-explorador-visor.js` e `pasta-documentos-sugeridos.js`. Bloco de topo
  `pasta_documentos` do catálogo, 314 chaves, em sub-blocos por arquivo (`sugeridos` 50, `explorador` 97, `checklist`
  43, `metas` 72, `anotacoes` 37, mais o `fechar` comum) e `pasta_documentos.js.*` (14) para os dois JS.
- **Catálogo do JS:** os dois scripts nascem dentro do próprio lote (`defer`, no `_documentos_explorador` e no
  `_documentos_sugeridos`, que só é incluído pelo explorador). O `catalogo_js('pasta_documentos.js')` mora no
  `_documentos_explorador`, logo antes do visor e fora de condição dentro dele; o show não foi tocado.
- **Sem mudança visível:** o HTML da pasta vazia (checklist, metas e registro vazios, sem catálogo de documentos), da
  pasta cheia (checklist com itens marcados e pendentes, explorador com seção e arquivos, sugestões pelo catálogo,
  metas atrasada, aberta, em revisão e concluídas no prazo e com atraso, sete registros com "ver anteriores"), das
  sugestões com um documento já existente e do checklist completo saiu igual. Mudaram só tokens CSRF, quebras de linha
  (frases que ocupavam várias linhas viraram uma) e o `<script data-bj-t>`; na foto cheia, a ordem de dois arquivos com o
  mesmo horário no trilho da aba Dados (fora deste lote) variou entre as rodadas. O texto dos dois JS foi conferido trocando
  cada `BJ_T.t` pelo texto do catálogo. Não foram renderizados, e foram conferidos lendo: o painel com leitura do Push
  (confiança média, exigido pelo juízo, prazos em curso, origem e as notas "lido"), o conflito de número de processo,
  os duplicados, o checklist desativado e a confirmação com um pendente só, a pasta excluída (nota de só leitura do
  registro, metas e checklist sem escrita), a resposta, o "editado", o sino de alertar e a meta com um dia de atraso.
- **Formatos:** com guarda de nulo, `checklistDesativadoEm`, o `data-meta-prazo`, a conclusão e a última alteração da
  meta passaram a `|data`, e a edição do registro a `|data` e `|hora`. Ficaram com `|date` os que não têm guarda
  explícita (prazo da meta nos títulos e no "vence", a data da coluna da meta, a hora e o dia do registro).
- **Escolhas:** singular e plural viraram duas chaves pelo mesmo teste de antes (publicações lidas, pares parecidos,
  documentos pendentes, dias em atraso, minutos da edição, a legenda do andamento e a "atenção" das metas); o
  "Adicionar N faltante(s)" continua com "(s)", como no §8. O "Nenhum item. Clique em [+] para adicionar." ficou uma
  chave por pedaço, em volta do ícone. Os rótulos dos grupos de sugestão, dos filtros e modos do explorador, dos filtros
  e status das metas e os meses da pílula do registro viraram chaves nos arrays do Twig, traduzidas no uso. Ficaram nos
  permitidos o enum `MotivoDesativacaoChecklist`, a constante `RenomearMetaUseCase::TAMANHO_MAXIMO` e as teclas do visor.
- **Ficou de fora:** o texto do PHP (o `rotulo` do `MotivoDesativacaoChecklist`; selo e dica de `cobrancas_do_checklist`;
  fase, base, nomes, porquê, origem, prazos, conflito e pastas sugeridas de `documentos_sugeridos`; o `#pexDados` do
  `ExploradorDeDocumentosOutput`; `prazoConcluida`, a atenção e o relatório da meta do `PastaMetasResumoOutput`; o
  título do alerta de `metas_ultimos_alertas`; o `registro_texto`); os JS `pasta-explorador.js`, `pasta-checklist.js` e
  `pasta-metas.js` (o show os carrega sem `defer`, e nenhum parcial do lote é incluído sempre: os três ficam sob
  `abasOcultas`); `pasta-mencoes.js` e `editor-rico.js`, usados também fora da Pasta; e o `lang="pt-BR"` e o
  `toLocaleString('pt-BR')` da impressão do visor.

### 20.6 Detalhes (4b, 21/10)

- **Escopo:** `pasta/_detalhes_obs.html.twig` (o painel "Relatório inicial de Atendimento", o macro `observacao` e o
  script inline que monta o cartão novo, a caixa de resposta, a edição, o ⋮ e a exclusão) e o `pasta-ler-mais.js`, que
  só este parcial carrega. Bloco de topo `pasta_detalhes` do catálogo, 88 chaves: 11 na raiz, `cartao` 13, `menu` 11 e
  `pasta_detalhes.js.*` (53) para o script inline e o `pasta-ler-mais.js`.
- **Catálogo do JS:** o `catalogo_js('pasta_detalhes.js')` mora no próprio `_detalhes_obs`, antes dos `<script>` dele; o
  show não foi tocado. O script inline roda antes do `bj-traducao.js` (fim do corpo), então só pede texto na hora do
  uso: a constante `ROTULO_ORFA` virou a função `rotuloOrfa()`. O `pasta-ler-mais.js` é `defer` e roda depois do
  `bj-traducao.js`. O cartão montado no JS passa o texto do catálogo por `esc()`, como o do Financeiro.
- **Sem mudança visível:** o HTML da aba foi comparado antes e depois em seis casos (vazia e cheia, para quem edita,
  quem só vê e na lápide; a cheia com raiz, resposta de outro dia, órfã, observação editada, de outro autor, sem nome,
  com 1 minuto de janela e com nome e texto com apóstrofo, aspas, `&` e `<b>`), sem diferença além de tokens CSRF e do
  `<script data-bj-t>`. O script inline foi rodado num DOM falso, antes e depois, montando o cartão (raiz, resposta,
  órfã, sem autor, com e sem o encaminhar), a caixa de resposta, a edição, a dica do "editado", a janela do ⋮, a
  confirmação de exclusão, os avisos de copiar e do Chat I.A e os erros de envio, edição e exclusão: mesma saída. O
  rótulo do "continuar lendo" deu o mesmo texto para 1, 2 e 7 parágrafos.
- **Formatos:** a hora do cartão e a dica do "editado" passaram a `|hora` e `|data`. Ficaram fixos o `title` da hora
  (`d/m/Y H:i`, que o JS lê para comparar o dia da resposta), o `d/m` da resposta de outro dia, o `d/m/Y, H:i` do
  cabeçalho do ⋮ e do `data-quando` da impressão, e a pílula do dia com os meses abreviados (`meses` no Twig e `MESES`
  no JS), como no Financeiro (§17).
- **Escolhas:** "Editável por mais N minuto(s)" virou duas chaves (`editavel_um`, `editavel_varios`) pelo mesmo `== 1`
  de antes, no Twig e no JS; o mesmo no "continuar lendo (N parágrafo/parágrafos)". Os motivos do bloqueio
  (`motivo_prazo`, `motivo_autor`) seguem com o "15 minutos" no texto, como antes. Sem permitidos na guarda.
- **Ficou para depois:** o `editor-rico.js` (carregado também pelo Financeiro, pelos Dados da pasta, pelo objeto da
  Cobrança e pela tarefa); as mensagens `erro` que o `PastaController` devolve no JSON das rotas de observação; e os
  comentários do arquivo, que não vão à tela.

### 20.7 Peticionar (4b, 21/10)

- **Escopo:** `pasta/peticionar` (com os dois scripts inline) e o `peticionar-documentos.js` (carregado só pelo
  Peticionar). O `layout_peticionar` ficou com a extração da raiz (§24, chaves `peticionar.*`, `moldura.*` e
  `menu.usuario.*`); daqui só entrou nele o `bj-traducao.js` (abaixo). Bloco de topo
  `pasta_peticionar` do catálogo, 89 chaves (`lista` 8, `barra` 5, `preview` 3, `salvar_texto` 3,
  `envio` 10, `descarte` 4, comuns 12), com `pasta_peticionar.js.*` (44) para o script inline e o JS. Área
  `pasta_peticionar` no `templates-extraidos.json`, com a marca (`BlueJus`, `| BlueJus`), a constante das categorias,
  as teclas, o `Content-Type`, o `Files` do arrastar e dois pedaços do `map(ext => ...)` do `accept` que o detector
  lê como texto (o `=>` parece fim de tag) nos permitidos.
- **Catálogo do JS:** o `layout_peticionar` não estende o base e não tinha o `bj-traducao.js`; passou a carregá-lo
  (sem `defer`) logo antes do bloco `javascripts`, como o base. O `catalogo_js('pasta_peticionar.js')` abre o bloco
  `javascripts` da tela, antes do script inline e do `peticionar-documentos.js`.
- **Sem mudança visível:** o HTML de 5 situações (quem edita com pasta cheia, com cliente, ação, processo completo com
  polo ativo e passivo, seção, notificação e quatro tipos de documento; pasta vazia sem cliente nem processo; pasta
  excluída; quem só vê, com a pasta cheia e a vazia), com dados que têm apóstrofo, aspas, `&` e `<b>`, foi gravado
  antes e depois e comparado com tokens, dígitos e espaços normalizados: saiu igual. Mudaram só o `<script data-bj-t>`
  e o `bj-traducao.js` que entraram, as aspas da dica da lista vazia (`"Adicionar peça"` agora sai como `&quot;`, o
  mesmo texto na tela) e os literais dos scripts inline, trocados por `BJ_T.t()`; cada chave nova foi conferida contra
  o texto que ela substituiu.
- **Formatos:** o dia do separador da lista e o `|date('d/m/Y')` da distribuição viraram `|data`, o
  `data-carregado-em`, `|data_hora`, e a hora da linha, `|hora` (todos com data sempre presente).
- **Ficou para depois:** os rótulos das categorias (`categoriaLabels` do `PeticionarController`), o `uploadedAt` e as
  mensagens (`mensagem`, `error`, `erro`) que as rotas de envio, texto e exclusão devolvem ao JS; o tamanho do arquivo
  (`number_format(1, ',', '.')` no Twig e `toFixed(1).replace('.', ',')` no JS, que precisam mudar juntos, e o
  `formatarBytes` do aviso de redução, que escreve `1.5 MB` com ponto); o `language: 'pt_BR'` do TinyMCE; o
  `lang="pt-BR"` fixo do `layout_peticionar`; e o `'Falha ao carregar o documento'` do script inline, que só vai ao
  console.

### 20.8 A tela principal (show)

- **Escopo:** `pasta/show.html.twig`, o que é dele (o registro da pasta na aba Detalhes e as janelas Adicionar
  pagamento, Mover para, Editar documento, Adicionar cliente, Vincular processo, Prévia de documento, Editar dados e
  Nova meta) e os sete scripts inline (cliente, envio de arquivo, registro da aba Dados, vincular processo, editar
  dados, situação e prioridade, nova meta). Bloco de topo `pasta_show` do catálogo, 344 chaves: 224 do Twig (`registro`
  9, `pagamento` 32, `documento` 12, `cliente` 37, `processo` 68, `editar` 23, `meta` 37 e as comuns) e
  `pasta_show.js.*` (120) para os scripts inline. Área `pasta_show` no `templates-extraidos.json`.
- **Catálogo do JS:** um `{{ catalogo_js('pasta_show.js') }}` antes do primeiro script inline (o do cliente), fora de
  condição. Os scripts rodam antes do `bj-traducao.js` (fim do corpo do base), então só pedem texto dentro de funções:
  as constantes `ROTULO_ORFA`, `REG_MOTIVO_PRAZO`, `REG_MOTIVO_AUTOR` e `ROTULO_CRIAR` viraram `rotuloOrfa()`, a
  chamada no uso e `rotular(btn, ICONE_CRIAR, ...)`. Os scripts foram rodados num ambiente sem `BJ_T` e nenhum o pede
  ao carregar. Texto do catálogo que entra em HTML montado passa pelo `esc`/`escHtml`/`mpEsc` do próprio script.
- **Sem mudança visível:** o HTML da pasta cheia (processo, cliente, metas, documentos, anotações e relatório), vazia,
  excluída, vista por quem só lê e com abas ocultas (para a colaboradora e para o primário) foi gravado antes e
  depois e comparado com tokens CSRF, dígitos e espaços normalizados: saiu igual. Mudaram só o `<script data-bj-t>`,
  as quebras de linha e as linhas dos scripts inline, comparadas trocando cada `BJ_T.t` pelo texto do catálogo (as
  com parâmetro, conferidas uma a uma). O `"Buscar no CNJ"` da nota das movimentações sai como `&quot;` (mesmo texto
  na tela). Não foram renderizados, e foram conferidos lendo: o "Sem registro" (pasta sem modificação), o
  identificador montado da cobrança ("Montado a partir do credor..."), o "Nenhuma aba disponível" (todas ocultas) e a
  pasta sem número na Nova meta.
- **Formatos:** o `modificadoEm` do Registro da pasta (sob guarda de nulo) passou a `|data_hora`; o `criadoEm`, sem
  guarda, ficou com `|date`. A dica do "editado" do cartão montado no JS passou de `toLocale*('pt-BR')` a
  `BJFormato.data` e `BJFormato.hora`.
- **Escolhas:** "Vincular 1 Cliente" e "Vincular N Clientes" viraram duas chaves pelo mesmo teste de antes; o
  "movimentação(ões)" continua com "(s)", como no §8. A frase com `<strong>` do identificador montado ficou uma chave
  por pedaço. As perguntas do processo (segredo, 100% digital, gratuita, tutela, prioridade) viraram chaves no array
  do Twig, traduzidas no uso. As letras dos dias da semana do calendário da Nova meta são uma chave cada. Ficaram nos
  permitidos as siglas (`CPF`, `CNPJ`, `RG`, `CEP`, `UF`), os exemplos (`SSP/SP`, `SP`, `R$ 0,00`), a classe
  `ps-body`, o enum `TipoDeApenso` e a constante `Pasta::ROTULOS_SITUACAO`.
- **Ficou de fora:** o texto do PHP (rótulos do `TipoDeApenso` e de `Pasta::ROTULOS_SITUACAO`, `documentTypeOptions`,
  `identificador_pasta`, `documento_br_rotulo`, `cliente_qualificacao`, as mensagens `erro`/`error`/`aviso` das
  rotas que os scripts mostram); os parciais incluídos pelo show (de outras frentes ou já extraídos); os JS externos
  (`pasta-explorador.js`, `pasta-checklist.js`, `pasta-metas.js`, `pasta-apensado.js`, `pasta-destaque.js` e os
  demais); o `formatBytesPeca` do envio (`toFixed` com ponto) e os meses abreviados (`MESES`) da pílula do dia do
  cartão montado no JS, como no §20.6.

## 25. E-mails e as páginas que eles abrem (5d, 21/10)

Três PRs, um aberto por vez, sem migration e com o português idêntico:
- **A (páginas públicas):** `comercial/confirmacao/*`, `email_descadastro/*`, `indicacao/_janela` (mais o `indicacao.js`)
  e `invitation/index`, no bloco `publicas:`;
- **B (e-mails do sistema):** cadastro, senha, exclusão de conta, convites, Push, resumo diário e Trabalho Externo;
- **C (e-mails do Comercial):** `email/comercial/*`.

Na entrega A, são 52 chaves (`publicas.confirmacao`, `.descadastro`, `.indicacao`, `.convite` e `.js.indicacao`).
- A página sem login sai em pt_BR, pelo `IdiomaDaRequisicaoListener`, e as 5 páginas avulsas não têm JS.
- A frase com `<b>` ou `<strong>` no meio foi partida em chaves, com a marcação no template.
- **Ficam como estão:**
  - o texto que o servidor manda à janela de Indicações (controller e UseCases);
  - os rótulos do FormType do convite;
  - o `lang="pt-BR"` fixo das páginas avulsas.
- **Para decidir:** `invitation/index.html.twig` não é renderizado por nenhum controller e parece morto.
- **Prova:** 12 renderizações antes e depois, cobrindo os ramos, saíram iguais. A janela só ganhou o bloco do catálogo
  JS.

### 25.B Os e-mails do sistema (5d, 22/10)
- **Escopo:** os 11 templates de `email/` fora de `email/comercial/`, no bloco `emails:` (85 chaves,
  `emails.<template>.*`). A frase com marcação no meio foi partida, e a pontuação entre as tags ficou no template.
- **Formatos:** `|date('d/m/Y')` virou `|data` (em `convite_escritorio`, `convite_plataforma`,
  `trabalho_externo_convite` e `resumo_diario`), e `d/m/Y H:i` virou `{data} às {hora}`. O fuso é o mesmo. As
  contagens entram no ICU como texto, sem separador de milhar.
- **Fora de requisição:** worker e comandos saem em pt_BR pelo `default_locale`. A prova rodou sem requisição.
- **Ficam no PHP:** os assuntos dos mailers (`RedefinicaoSenhaMailer`, `ConviteMailer`, `CadastroMailer`,
  `CodigoDeExclusaoMailer`, `TrabalhoExternoMailer`, `AvisoDoPushMailer`, `EnvioDeEmailAoClientePeloMailer`,
  `IndicarPorEmailUseCase` e `EnviarResumoDiarioDoEscritorioUseCase`) e o `MovimentacaoDoAvisoOutput::TEXTO_SIGILOSA`.
- **Entrega E (idioma do destinatário):** os convites de escritório, de plataforma, do Trabalho Externo e da
  Indicação, o push ao cliente e o aviso do Push à equipe. Hoje saem no locale de quem dispara, e sem requisição, em
  pt_BR.
- **Prova:** 17 renderizações, com `&`, aspas, apóstrofo, acento e `<b>` nos valores, saíram iguais byte a byte.

### 25.C Os e-mails do Comercial (5d, 22/10)
- **Escopo:** `email/comercial/**`: o layout, a confirmação de endereço e as 6 automações, mais os macros `_blocos`. São
  55 chaves em `emails.comercial.*`, dentro do bloco `emails:`, e a área nova é `emails_comercial`. O conteúdo das
  campanhas do Master, escrito pelo usuário, não é traduzido. Só o texto fixo do layout vai ao catálogo.
- **Formatos:** o `{limite}%` do aviso de 90% entra como texto, e o `{escritorio}` continua escapado.
- **Ficam no PHP:** o `ModelosDeAutomacao::MODELOS` (assunto, selo e título), o `ConfirmacaoDeEndereco::ASSUNTO`, o
  `RotulosDoConsentimento`, o "colega" e o "o escritório" do `MontadorDeEmail`, e os textos da `ComposicaoDaCampanha`
  e do `RenderizadorDeBlocos`.
- **Prova:** 39 renderizações completas, com o layout e sem requisição, saíram iguais byte a byte: as 8 automações
  nos três envelopes, a confirmação com duas origens, as campanhas simples, de blocos e html (normal, com rastreio e
  na prévia), o envelope cru e o envelope com motivo.

## 26. Extração do Trabalho Externo, Central e ServiceDesk (a7, 21/10)

- **Escopo:** 12 templates. `templates/trabalho_externo/` `index` (casca do painel: título, buscas e cabeçalho da
  tabela), `convite`, `sem_acesso`, `curriculo_documento` (pré-visualização e PDF) e `vagas_publicas` (página pública,
  incluindo `<title>`, `og:title`/`og:description` e o texto da mensagem do WhatsApp); `templates/central/` `index`,
  `_janela` e `_flutuante`; `templates/servicedesk/` `index`, `meus_chamados`, `novo` e `show`. Blocos novos do
  catálogo: `trabalho_externo` (161 chaves), `central` (34) e `servicedesk` (94). Nenhum JS entrou.
- **Sem mudança visível:** com o mesmo `FOUNDRY_FAKER_SEED`, as 160 respostas HTML dos functionals de
  `tests/TrabalhoExterno`, `tests/Central` e `tests/ServiceDesk` (painel, convite nos 5 estados, sem acesso, currículo
  formatado, vagas públicas com e sem vagas e pelo link, Central em página e flutuante em toda tela, painel, meus
  chamados, abrir e detalhe do chamado) saíram iguais antes e depois, comparadas com espaços normalizados e números e
  tokens trocados por marcador. Os números que entram em frase (`Chamado #{id}`, `Chamados ({total})`,
  `{total} vaga(s) aberta(s)`, `{qtd} vagas`) vão como texto (`~ ''`), para o ICU não pôr separador de milhar.
- **Frase partida:** as frases com `<strong>`, `<b>` ou link no meio (convite: serviço e local, termos de uso,
  política de privacidade; ServiceDesk: "N chamado(s) aguardando atribuição" e a legenda das prioridades do `novo`)
  foram divididas em chaves, como no §8, com a pontuação e a marcação no template. A mensagem do WhatsApp das vagas
  virou duas chaves inteiras (com e sem vagas) com `{total}`, `{cargos}` e `{url}`; a URL foi para um `{% set %}`.
- **Guarda/permitidos:** `BlueJus` (alt e marca do rodapé do currículo), `te-body` (classe do `body_class`) e os nomes
  de constante lidos por `constant()` (`CriarConviteEscritorioUseCase::RECUSA_EMAIL_A_CONFIRMAR` no painel,
  `Mensagem::TAMANHO_MAXIMO` na janela da Central). Os `data-screen-label` da página de vagas (rótulos do desenho,
  ninguém lê na tela) ficaram como estão.
- **Formatos:** no ServiceDesk, `|date('d/m/Y H:i')` virou `|data_hora`, e o `d/m/Y`/`H:i` da linha do tempo do
  detalhe, `|data`/`|hora`. Ficaram: `|number_format(1)` do tempo médio e do tempo de resolução (o padrão do Twig
  escreve "2.5"; o `|numero(1)` escreveria "2,5", mudança visível), que entram como `{valor}` em `servicedesk.comum.horas`.
  As datas do convite (`convite.validade`) e das vagas (`pagina.geradoEm`) já chegam formatadas do PHP.
- **Ficou para depois:** o `trabalho-externo.js` (lista, KPIs, chips, painel lateral, modais, vagas, currículos), o
  `vagas-publicas.js`, o `central.js`, o `central-flutuante.js` e o `central-dica.js`; o texto que vem do PHP: erros
  do convite (`erro`), `convite.servico`/`local`, os blocos do `DocumentoDoCurriculo` (rótulos de contato e de
  disponibilidade, situação, período), `pagina.consentimento` e as áreas das vagas, os rótulos de status, prioridade,
  categoria e tipo do ServiceDesk (`statusLabel`, `prioridadeLabel`, `categoriaLabel`, `tipoLabel`, `statusList`,
  `categorias`), os rótulos do `ChamadoType`/`InteracaoType` e os flashes dos controllers.

## 27. Extração do Escritório e da Plataforma (b5, 22/10)

- **Plano da frente** (um PR por etapa, sem idioma novo liberado, sem mudança visível):
  - **T1 escritório** (branch `idioma-tenant-1`): `tenant/index`, `new`, `criar`, `show`, `edit`, `_form`,
    `_delete_form`, `selecionar` e `escritorio_indisponivel`, com os flashes dessas ações.
  - **T2 usuários:** `tenant/users` e `tenant/edit_user_role` (a ação `editUserRole` e as de usuários do
    `TenantController`).
  - **T3 ponto:** `tenant/_jornada_colaborador_tab`, `_modal_jornada_tenant`, `_home_office_tab`, `_horas_pagas_tab`,
    `_justificativas_tab` e `ponto_edit`.
  - **T4 sedes:** `tenant/sedes`.
  - **A1 admin/platform:** os 5 templates de `admin/platform/`.
- **Catálogo (T1):** bloco de topo `escritorio:` (`# ---- escritorio`, antes do `# ---- termos`), 73 chaves:
  `lista` 11, `novo` 6, `criar` 5, `ver` 12, `editar` 14, `form` 2, `selecionar` 8, `indisponivel` 2, `flash` 13
  (`escritorio.form.*` é dos parciais `_form` e `_delete_form`, que nenhuma tela inclui hoje). A tela Ver reaproveita as
  colunas e o Ativo/Inativo de `escritorio.lista.*`. "Criar escritório", "Excluir escritório", "Editar escritório" e
  "Selecionar escritório" recebem `termos.escritorio` por parâmetro (`'Criar {termo}'`); as frases com "Escritório" em
  maiúscula ("Meu Escritório", "Dados do Escritório") ficam inteiras na chave. As contagens com "(s)" ("N escritório(s)",
  "N convite(s) pendente(s)") continuam como estão, com `{n}`.
- **Destaque sem `|raw`:** o aviso da Zona de perigo e o "Digite <nome> para confirmar:" são partes da frase em chaves
  separadas em volta do `<strong>` (`perigo_antes`, `perigo_destaque`, `perigo_depois`; `confirmar_antes`,
  `confirmar_depois`). O tradutor vê a ordem pelo comentário no catálogo; se outro idioma pedir outra ordem, a frase
  vira uma chave com marcação e `|raw` revisado (decisão para a entrega L).
- **Texto do PHP (T1):** traduzem no ponto de saída (`TranslatorInterface`): no `TenantController` (legado, edição
  cirúrgica), os dois erros de e-mail do Novo Tenant (`FormError`), os três flashes da criação (com o link de dev por
  `{link}`) e os três da edição (nome, cargos, lotações); no `EscritorioController`, "Escritório criado...",
  "Escritório excluído..." e a confirmação com o nome errado (a constante virou a chave
  `CHAVE_CONFIRMACAO_NAO_CONFERE`; nenhum teste comparava o texto); no `TenantSelecaoController`, os dois flashes do
  POST; na `BarreiraDoEscritorioInativoNaRota`, o título da página (o `ROTULO` continua o português e a chave fica ao
  lado, `CHAVE_DO_ROTULO`; o `CatalogoDoEscritorioTest` amarra os dois). A barreira roda depois do listener do idioma
  (prioridade 3 contra 7), então já lê o idioma de quem pede.
- **Formatos (T1):** na lista, `createdAt|date('d/m/Y H:i')` virou `|data_hora` (o `createdAt` sempre vem preenchido,
  o `|date` de nulo não entra). Na tela Ver, `|date('d/m/Y H:i:s')` fica como está: não há filtro com segundos (mesmo
  caso do `impressoEm` do Expediente, §9).
- **Permitidos (T1):** só `· BlueJus` (a marca no `<title>` da página de indisponível).
- **Sem mudança visível (T1):** um teste temporário (apagado antes do commit) gravou o HTML de 20 telas, com os
  templates e o PHP da HEAD e depois com os novos, normalizando tokens CSRF, nonces, ids, e-mails e datas: lista,
  Ver, Ver com o flash da confirmação errada, Editar, Editar com os três flashes, Criar com e sem OAB, Novo Tenant e
  Novo Tenant com o e-mail em uso, Selecionar com dois escritórios, vazio, com convite e com os dois flashes do POST,
  a página de escritório indisponível (403) e os parciais `_form` (com e sem `button_label`) e `_delete_form`. As 20
  ficaram iguais byte a byte.
- **JS inline com texto (fica como está, o teste não cobre `<script>` nem `onsubmit`):** no `edit`, o
  `removeBtn.title = 'Remover'` do botão de remover cargo ou lotação criado pelo script (o do HTML já usa
  `escritorio.editar.remover`); no `_delete_form`, o `confirm('Tem certeza que deseja excluir este Tenant?')` do
  `onsubmit`. Os templates do T1 não carregam JS próprio (`<script src>`).
- **Ficou fora do T1:** os rótulos, placeholders e mensagens dos Form types (`TenantType`, `TenantNameType`,
  `TenantCargosType`, `TenantLotacoesType`, `CriarEscritorioType`), como na P1; as recusas do
  `CriarEscritorioUseCase` (OAB obrigatória e limite de escritórios com `%d`) e do validador de OAB, que o
  `EscritorioController` repassa (precisam de chave na exceção, e a do validador é compartilhada com o Meu Perfil); os
  avisos de conta suspensa, excluída e suspensa a pedido (`AvisoDeContaSuspensa`, `BloqueioPorPedidoDeExclusao::MENSAGEM`)
  e as mensagens de acesso bloqueado (`EquipeDoEscritorioRepository`), que a seleção compara com o flash posto pelas
  barreiras e que a página de indisponível e o JSON 403 também usam; as recusas da `RegraDosMasters` e a
  `PedidoDeExclusaoRecusado` na exclusão (outros domínios, como na P2b); as mensagens das 403 (página de erro padrão);
  o `|date` com segundos da tela Ver; o `lang="pt-BR"` fixo do `escritorio_indisponivel` (página solta, sem o base);
  e a ação `sair` do `EscritorioController` (não é tela do T1).
- **T2 usuários** (branch `idioma-tenant-2`, sobre o T1): `tenant/users` e `tenant/edit_user_role`, dentro do mesmo
  bloco `escritorio:`, com `escritorio.usuarios.*` (21 chaves), `escritorio.editar_usuario.*` (49, com as 5 de
  `recusa.*`) e 6 flashes novos em `escritorio.flash.*`. "Remover do escritório" recebe `termos.escritorio` por
  parâmetro; a contagem de acessos específicos é um plural ICU (`{n, plural, one {# item} other {# items}}`), com o
  "items" de hoje. O aviso da remoção ("Ao remover <strong>nome</strong>, ela perde...") é o mesmo molde do T1, em duas
  chaves em volta do `<strong>`. A lista usa `|data_hora` no `createdAt` (coluna não nula, preenchida no construtor).
- **Texto do PHP (T2):** no `TenantController` (edição cirúrgica, nenhuma condição mexida), os flashes de salvar o
  colaborador, do nome (token inválido, vazio, atualizado), de remover o colaborador (com `{nome}`) e de remover acesso
  específico vão pelo tradutor. As recusas do edit-role e da remoção passam por um `traduzirRecusa()`: a constante
  continua o português (o que as regras devolvem e os testes comparam, inclusive C5 e C8) e a tela traduz pela
  `CHAVE_DA_RECUSA` da `HierarquiaDePerfis` (do #231) ou da `RegraDosPrimarios`. Na `RegraDosPrimarios` as quatro
  recusas que o edit-role diz (gerir primários, conta do Master, titular, último primário) viraram constantes `RECUSA_*`
  (o texto é o mesmo, então os outros consumidores, Equipe, convites e o `RemoverColaboradorDoEscritorioUseCase`, não
  mudam), mais a `RECUSA_SENHA_DE_PRIMARIO`, que era uma string só do controller. O `CatalogoDoEscritorioTest` amarra
  constante e catálogo. A recusa que não tem chave sai como veio.
- **Sem mudança visível (T2):** um teste temporário (apagado antes do commit) gravou o HTML de 29 casos com o código da
  HEAD e com o novo, normalizando tokens, nonces, e-mails e números (as datas foram conferidas à parte, sem
  normalizar): a lista como primário e como secundário com `admin.users.manage` (com acessos específicos de 1 e 2
  itens), o edit-role de uma secundária, do titular e do sócio pelo titular, do sócio, de quem está acima e de quem usa
  outro escritório pelo secundário; as recusas do titular rebaixado, C8 (pelo titular e pelo secundário), C5 (alvo
  acima, com e sem senha), próprio perfil, perfil acima do seu, primário forjado, senha de primário, conta do Master
  (perfil e senha); os flashes de salvar, da recusa C5 na remoção, de remover, de remover a si mesmo e de remover o
  Master, de nome (token, vazio, ok) e de acesso removido. Os 29 ficaram iguais byte a byte, menos o aviso do modal de
  remoção, que era um parágrafo quebrado em três linhas e virou uma linha só: iguais com o espaço colapsado.
- **JS inline com texto (fica como está):** no `users`, o `localeCompare(..., 'pt-BR')` da ordenação da tabela; no
  `edit_user_role`, o `<script>` das abas não tem texto, e o `confirm('Remover este acesso específico?')` do `onsubmit`
  fica fixo (como o do `_delete_form` no T1).
- **Ficou fora do T2:** os rótulos e as mensagens do `EditUserTenantRoleType` e do `DadosPessoaisType` (como na P1),
  incluindo o "Selecione um perfil" e a ajuda da Controladoria; as parciais das abas (`_jornada_colaborador_tab`,
  `_home_office_tab`, `_justificativas_tab`, `_horas_pagas_tab`, `_modal_jornada_tenant`, do T3, e
  `ponto/_folha_table`); os flashes das ações de ponto e de justificativas que voltam ao edit-role (T3, risco ALTO); o
  flash dos dados pessoais (`TenantUserProfileController`, fora do `TenantController`); as recusas próprias do
  `RemoverColaboradorDoEscritorioUseCase` ("Para deixar o escritório...", criador, último administrador, substituto),
  que precisam de chave na exceção; a recusa do convite de primário da `RegraDosPrimarios` (não é dita nestas telas);
  as mensagens das 403 e 404; o `resourceType` cru (`cliente`, `pasta`) do selo dos acessos específicos, que é dado.
- **T4 sedes** (branch `idioma-tenant-4`, sobre o T2): a parte Twig de `tenant/sedes` (até o `{% block javascripts %}`),
  dentro do bloco `escritorio:`, com `escritorio.sedes.*` (30 chaves) e 5 flashes novos em `escritorio.flash.sede_*`.
  "Sedes" serve ao `<title>` e ao cabeçalho (`titulo`), com o ` | nome do escritório` fora da chave, como hoje. A
  contagem do selo é um plural ICU com o número já como texto (`{n, plural, one {{qtd} sede} other {{qtd} sedes}}`,
  como o `escritorio.usuarios.itens`), e o raio da cerca é `'{raio}m'` com o inteiro como texto (sem separador de
  milhar). As siglas das UFs do `<select>` são dado: saíram do HTML para uma lista no próprio template, num
  `{% for %}` em pares (`|batch(2)`) que reproduz a quebra de linha de antes; só o "UF" da primeira opção tem chave.
  As coordenadas do selo (`|round(5)`) e os `data-sede-*` que o script lê ficam como estão: são dado, e um `|numero`
  trocaria o ponto pela vírgula. A página não tem `|date` nem `number_format`.
- **Texto do PHP (T4):** no `TenantController` (edição cirúrgica), os cinco flashes das ações `manageSedes`,
  `editSede` e `deleteSede` vão pelo tradutor; o "N registro(s) de ponto foram desvinculados..." recebe o total como
  texto (`{qtd}`), igual ao `%d` de antes. As sedes não têm UseCase próprio nem constante de recusa que teste compare.
  O laço de flashes do próprio `sedes.html.twig` fica como está e, na prática, não mostra nada: o `base` consome os
  flashes antes do bloco `body`.
- **Sem mudança visível (T4):** um teste temporário (apagado antes do commit) gravou o HTML de 9 casos com o código da
  HEAD e com o novo, normalizando tokens CSRF, nonces e ids: a lista vazia, com uma sede (selo no singular), com três
  (plural, nome com aspas, `&` e `<`, raio de 1500), criar com sucesso (flash), criar com erro de validação, editar com
  erro (o formulário em modo edição, título e botão de editar), editar com sucesso, excluir e excluir com dois
  registros de ponto desvinculados (os dois flashes). Os 9 ficaram iguais byte a byte. O "Sem coordenadas" não foi
  renderizado: `latitude` e `longitude` são não nulas no banco e o decimal volta como texto não vazio.
- **JS inline com texto (fica como está, o teste não cobre `<script>` nem `onsubmit`):** o `confirm('Excluir a sede
  ...permanentemente?')` do `onsubmit` da exclusão; no `<script>` do mapa, o título e o botão do modo edição e do modo
  novo ("Editar Sede", "Atualizar Sede", "Nova Sede", "Salvar Sede", montados por `innerHTML` com o mesmo texto do
  Twig, sem ler do HTML), os retornos da busca de CEP e endereço ("Localização encontrada: ...", "(aproximado pelo
  bairro)", "(aproximado pela cidade)", "Não foi possível localizar automaticamente...", "Informe pelo menos um
  endereço para buscar.", "Nenhum resultado encontrado...", "Digite um CEP ou endereço para buscar.", "Nenhum
  resultado. Clique em ✎...", "Erro ao buscar endereço..."), os `alert` do GPS ("Geolocalização não suportada neste
  navegador.", permissão negada, posição indisponível, tempo esgotado, "Erro ao obter localização."), o "Brasil" das
  buscas, a atribuição "OpenStreetMap contributors" e o `accept-language=pt-BR` do Nominatim. O JS é a frente dos
  scripts (com o `t()` do catálogo no navegador).
- **Ficou fora do T4:** os rótulos e as mensagens do `SedeType` (latitude e longitude obrigatórias, faixas de
  latitude, longitude e raio), como na P1; as mensagens das 403 e 404 das três ações ("Você não tem permissão para
  gerenciar sedes.", "Sede não encontrada." etc.); o `<script>` do mapa (acima). Achado fora do escopo, não
  consertado: editar uma sede com a longitude vazia dá 500 (`InvalidTypeException` no
  `Sede::setLongitude(string)`), em vez do erro de validação; criar com os campos vazios mostra o erro.
- **A1 admin/platform** (branch `idioma-admin`, sobre o T4): bloco de topo novo `plataforma:` (`# ---- plataforma`,
  antes do `# ---- termos`), 72 chaves: `menu` 5, `painel` 6, `convites` 19, `oab` 31 (com `filtro` 5) e `flash` 11. O
  `_base_platform` só tem CSS e o bloco `platform_body`: não ganhou chave (não há `plataforma.base.*`), mas entrou na
  lista dos extraídos. "Painel do Master", "Convites do Master" e "acompanhar os convites do Master" recebem
  `termos.master` por parâmetro (`{termo}`); o separador "Master" da barra lateral e o selo do painel usam
  `termos.master` direto. A contagem do selo da OAB é um plural ICU com `=1`
  (`{n, plural, =1 {{qtd} inscrição} other {{qtd} inscrições}}`): no pt_BR o `one` do ICU pega o zero também, e a tela
  de hoje diz "0 inscrições". O selo PRINCIPAL ou SUPLEMENTAR tem a chave em forma normal e a caixa alta no `|upper`.
  Os rótulos dos filtros e dos cartões do painel são traduzidos dentro do próprio `{% set %}`.
- **Formatos (A1):** os três `|date('d/m/Y H:i')` viraram `|data_hora`: o `createdAt` e o `expiresAt` do convite
  (não nulos no DTO) e o `oabVerificadaEm` da OAB, que já vinha protegido pelo ternário ("Sem registro"). O
  `reenvioCount` com o "/3" e os números da paginação são inteiros pequenos impressos crus, como antes.
- **Texto do PHP (A1):** traduzem no ponto de saída (`TranslatorInterface` no construtor): no `AdminConviteController`,
  os cinco flashes de criar, revogar e reenviar (o e-mail do convite vai por `{email}`); no `OabReviewController`, os
  seis (confirmada, destino inválido, revertida, as duas verificações e a inscrição que mudou depois da fila). Nenhum
  era constante: o `OabReviewTest` compara o texto do "A inscrição mudou..." na tela, que segue igual em português. O
  `PlatformDashboardController` só redireciona para o Painel do Master (o `dashboard.html.twig` não tem rota que o
  renderize hoje; foi extraído assim mesmo).
- **Sem mudança visível (A1):** um teste temporário (apagado antes do commit) gravou o HTML de 26 casos com o código da
  HEAD e com o novo, normalizando tokens CSRF, nonces, ids das inscrições, o token aleatório do convite criado pelo POST
  e o minuto de "agora": o painel (renderizado direto pelo Twig, com a barra lateral) e o redirecionamento da rota; os
  convites vazio e com itens (pendente com e sem reenvio, sem nome, aceito, revogado, expirado, e-mail com aspas e
  `&`); os sete flashes do convite (enviado, e-mail existente, revogado, revogar o que não está pendente, reenviado e
  as duas falhas de envio, com um mailer que falha); a OAB nos cinco filtros, com uma inscrição (selo no singular),
  vazia com e sem busca (0 inscrições) e na segunda página; os seis flashes da OAB (confirmada, inscrição mudou,
  destino inválido, destino `confirmada` pela recusa do UseCase, revertida, re-verificada). Os 26 ficaram iguais byte a
  byte depois da normalização.
- **JS inline com texto (fica como está, o teste não cobre `<script>` nem `onclick`):** nos convites, os
  `confirm('Reenviar convite para ...?')` e `confirm('Revogar convite de ...?')` dos `onclick`, com o e-mail montado
  pelo Twig. O `<script>` da OAB só liga os tooltips (sem texto). Nenhum dos 5 templates carrega `<script src>` próprio.
- **Ficou fora da A1:** as recusas dos UseCases que os controllers repassam (`$e->getMessage()`): "Já existe uma conta
  com este e-mail." do `CriarConvitePlataformaUseCase`; as do `RevogarConviteUseCase` e do `ReenviarConviteUseCase`
  (convite não encontrado, só pendentes, expirado, limite de reenvios, e o `RECUSA_EMAIL_A_CONFIRMAR` do
  `CriarConviteEscritorioUseCase`), que o convite do escritório (`GerenciarConvitesController`) também diz; o "Destino
  inválido para reverter OAB: ..." do `ReverterInscricaoOabUseCase` (com o valor do enum). Precisam de chave na exceção,
  como no T1. Também ficaram as mensagens das 403, 404 e 429 ("Token CSRF inválido.", "Convite de plataforma não
  encontrado.", "Inscrição não encontrada.", "Muitas tentativas..."); o selo de status da OAB
  (`_oab_status.html.twig`, compartilhado com o Meu Perfil); o `convite.status` cru do selo de status desconhecido
  (dado); e o comentário do CSS do `_base_platform`, que tem travessão mas não é texto de tela. O laço de flashes do
  próprio `convites.html.twig` fica como está e não mostra nada: o `base` consome os flashes antes (o que se vê é o
  aviso do `base`, com `alert-sucesso` e `alert-erro`).

## 26b. Extração de recurso, legal e termo (a7, 22/10)

- **Escopo:** 7 templates. `templates/recurso/` `_layout_simples` e `em_preparacao` (a página que o guarda de rota
  `BloqueioDeRecursoDesativado` mostra quando o Master desliga uma tela); `templates/legal/` `politica_privacidade`
  (página pública), `politica_privacidade_pdf` (casca do PDF) e `_politica_privacidade_texto`; `templates/termo/`
  `aceite` e `_texto`. Blocos novos do catálogo: `recurso` (2 chaves), `legal` (6) e `termo` (11). Nenhum JS entrou.
- **Decisão sobre o texto jurídico:** o corpo da Política (`legal/_politica_privacidade_texto`) e o dos Termos
  (`termo/_texto`) **não** foram extraídos e ficam fixos no template, fora do `templates-extraidos.json`. Nenhum hash
  é calculado do texto, mas a prova jurídica depende do arquivo: o aceite (`aceite_termo`) e a ciência
  (`ciencia_politica`) gravam só a versão (`TermoVigente::VERSAO`, `PoliticaPrivacidadeVigente::VERSAO`), e as duas
  classes amarram essa versão ao partial ("troque a constante e, no mesmo commit, atualize o texto"; a Política ainda
  guarda o histórico de versões no Anexo III do próprio arquivo). O texto que a pessoa aceitou numa versão é o
  partial no commit daquela versão. No catálogo, o texto passaria a poder mudar (revisão de idioma, varredura) sem a
  versão subir, e um segundo idioma faria a pessoa aceitar uma tradução que a versão gravada não distingue. Traduzir
  a Política ou os Termos é decisão jurídica, com versão própria. Foi extraída só a moldura: títulos de página,
  cabeçalho da tela de aceite, a declaração do checkbox, os links, os botões, o "Voltar ao topo" e o rodapé do PDF.
- **Sem mudança visível:** com o mesmo `FOUNDRY_FAKER_SEED`, as 123 respostas comparadas saíram iguais antes e depois:
  118 respostas HTML dos functionals de `tests/Legal`, `tests/Termo`, `Master/Functional/TelasERecursosTest`,
  `Pasta/Functional/PushLinkComTelaDesligadaTest` e `Auth/Functional/LoginDeContaAConfirmarTest` (página da Política,
  aceite com e sem a declaração da Política, tela em preparação, login e cadastro que passam pelo aceite),
  comparadas com espaços normalizados e tokens, ids e números trocados por marcador, e 5 renderizações diretas
  (HTML do PDF da Política, os dois partiais jurídicos e a tela em preparação com e sem previsão), iguais byte a byte.
- **Frase partida:** a declaração do aceite com a Política ("Li e aceito ... do BlueJus e a <link>.") virou
  `termo.aceite.declaracao_com_politica_antes` e `declaracao_com_politica_link`, com o espaço, o link e o ponto no
  template, como no §8. A variante sem a Política é uma chave inteira (`termo.aceite.declaracao`).
- **Guarda/permitidos:** `recurso` permite `BlueJus | BlueJus` (o título padrão do layout, marca, mais o sufixo de
  marca); `termo` permite `BlueJus` (o `alt` do logotipo); `legal` não tem permitidos. Os dois partiais jurídicos não
  entram na lista do teste, pela decisão acima.
- **Formatos:** nenhum filtro de data mudou. A previsão da tela em preparação (`previsao`), a versão e a data de
  publicação da Política (`data_publicacao`) chegam do PHP já como texto e entram como `{data}`/`{versao}`.
- **Ficou para depois:** o corpo jurídico (decisão acima); o PDF estático dos Termos (`public/termos/termos-de-uso.pdf`);
  o `lang="pt-BR"` fixo do `_layout_simples`, do `aceite` e da casca do PDF (páginas soltas, sem o base); o título e
  o motivo da tela em preparação, que vêm do PHP (`BloqueioDeRecursoDesativado`, catálogo de recursos do Master); o
  flash de erro do aceite (`TermoController`); e o JS inline do `aceite` (sem texto visível).
