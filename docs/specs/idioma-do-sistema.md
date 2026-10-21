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
  - **P2 Painel do Master:** `master/painel.html.twig`, `painel.js` e os mapas; possivelmente em dois PRs (P2a, P2b).
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

