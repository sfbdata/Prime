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
  - **P3:** Notificações, Indicações (sem o include da Central), `masters.js` e `recursos.js`.
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


## 9. A Pasta, aba Processo (0d, 21/10)

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
