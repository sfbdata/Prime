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

### 20.7 Peticionar (4b, 21/10)

- **Escopo:** `pasta/peticionar` (com os dois scripts inline), o `layout_peticionar` (só esta tela o estende; nenhum
  controller o renderiza direto) e o `peticionar-documentos.js` (carregado só pelo Peticionar). Bloco de topo
  `pasta_peticionar` do catálogo, 108 chaves (`layout` 19, `lista` 8, `barra` 5, `preview` 3, `salvar_texto` 3,
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
