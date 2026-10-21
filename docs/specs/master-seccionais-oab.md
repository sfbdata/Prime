# Seccionais da OAB no mapa "Onde estamos atuando" (desenho 05, Perfis 1.2 de 21/10/2026, F8)

Sessão f6 (corte da b5). Risco BAIXO/MÉDIO: só o Master (`ROLE_SUPER_ADMIN`) vê e escreve.

Desenho: `docs/design/perfis-1.2/vs-21-10-26/05 - Perfis 1.2 - VS 21.10.26.dc.html`, template L116-127 (bloco `ehOab`
abaixo da tabela de UFs), lógica L898-905. Desenho conferido inteiro em 21/10/2026 (286394 bytes).

## Estado da frente

- 21/10: spec escrita, worktree `master-seccionais-oab` (base master). Migration reservada `Version20261020148400`
  (pedir outra se o master passar dela na hora do PR).
- 21/10: spec conferida com o desenho inteiro (seção no fim). O que já foi implementado precisa ser revisto contra
  esta conferência.
- 21/10: frente `oab-desenho-05` (worktree própria), migration `Version20261020149500` (só acrescenta: `visivel` e a
  marca `marca_cor`/`marca_status`/`marca_em` na `master_seccional_oab`, `cidade` na `master_subsecao_oab` e a tabela
  `master_nota_cidade_oab`). Alinhado ao desenho: modo OAB no seletor "quem" (padrão Escritórios), linhas
  "OAB/<UF> · <Estado>" com o cartão, 6 status com cor e data gravados na seccional, visibilidade no banco (botão
  direito e "Mostrar todas"/"Todas visíveis", sem localStorage), inscrições por `inscricao_oab`, rótulos do desenho
  no cartão e no "Editar sede", site com `http://` ou `https://`. A tabela `master_divulgacao_seccional` fica no
  banco, intocada; entidade, enum e repositório marcados como obsoletos. Divergências: site com esquema inválido é
  recusado (422) em vez de ficar vazio; no iframe do modo OAB as cidades vêm das pessoas. Falta: revisão e o smoke
  do dono na tela.

## O que o desenho mostra (template L116-127)

Embaixo da tabela de UFs do mapa, um bloco "SECCIONAIS VISÍVEIS":

1. Cabeçalho "SECCIONAIS VISÍVEIS" com o botão `secTodas`/`secTodasTxt` ("Mostrar todas" com filtro, "Todas
   visíveis" sem filtro; desenho L902).
2. Grade com os 27 chips de UF (`secChips`, 34px de largura mínima, 22px de altura):
   - clique: abre o cartão da sede da seccional; segundo clique no mesmo chip fecha (`x.ver`, desenho L902);
   - botão direito: mostra ou oculta a seccional no mapa (`x.tgCtx`);
   - ponto colorido no chip: seccional já trabalhada (`x.mkTem`, `x.mkCor`);
   - chip selecionado com contorno `2px solid #0a6fa0`; chip visível com borda `#0a6fa0`, fundo `#e5f1f7`, texto
     `#0a6fa0`; oculto com borda `#d3dde4`, fundo `#fff`, texto `#9aabb7` (desenho L902).
3. Cartão da sede (`secTem`): faixa azul com `bi-bank`, o título (`secTit`) e o X; linhas `bi-people` com
   "N inscrição(ões) de usuários do BlueJus" (`secN`), `bi-geo-alt` com o endereço (`secEnd`), `bi-telephone` com o telefone (`secTel`, só se houver) e o link
   "Site da seccional" (`secSite`, só se houver); botões "Endereço oficial (CFOAB)" (link fixo
   https://www.oab.org.br/institucionalconselhofederal/asiseccional) e "Editar sede" (só Master, `souMasterB`).
4. Nota literal: "Clique numa seccional para ver a sede. Botão direito na lista mostra ou oculta no mapa; botão direito
   no mapa registra a divulgação (material enviado, reunião, parceria). O ponto colorido indica seccional já trabalhada.
   Fonte: Conselho Federal da OAB."

## Contrato

- **Quando o bloco aparece (`ehOab`, desenho L902, L905).** O seletor "quem" do mapa do Brasil ganha um terceiro modo:
  "Pessoas" (`bi-people`), "Escritórios" (`bi-buildings`) e "OAB" (`bi-bank`), nessa ordem; o padrão continua
  "Escritórios". O bloco das seccionais só aparece no modo "OAB". O seletor só existe no Brasil (L114), então no Mundo
  não há bloco. (Antes: a spec punha o bloco na visão "Pessoas".)
- **Tabela de UFs no modo OAB (desenho L875, L898, L900).** Subtítulo do mapa "inscrições por seccional da OAB"; título
  da coluna "Inscrições"; nome de cada linha "OAB/<UF> · <Estado>", com cursor de mão; clique na linha abre o cartão da
  sede daquela UF. Nos outros modos a linha não tem ação nem mão. O mapa recebe o modo `oab`.
- **Número da linha e `secN`.** O número da linha no modo OAB é a contagem de inscrições de usuários do BlueJus naquela
  seccional, pela tabela `inscricao_oab` (principal e suplementar). O cartão repete o mesmo número: "N inscrição(ões)
  de usuários do BlueJus" (texto literal do desenho L903, sem flexionar). **DONO (padrão adotado, a confirmar com o dono):** conta todas as
  inscrições de quem tem vínculo ativo, sem olhar a situação da inscrição. (Antes: "N usuários" com `COUNT(DISTINCT user_id)`.)
- **Título do cartão (`secTit`, L903).** "OAB/<UF> · Seccional <Estado>".
- **Dados das sedes.** Tabela de plataforma com as 27 seccionais (UF, nome, endereço, telefone, site). O desenho não
  traz endereços: tudo é cadastrado pelo Master no "Editar sede". A carga da migration grava só a UF e o nome;
  endereço, telefone e site ficam vazios. Sem endereço o cartão mostra "Endereço da sede: consulte a página oficial"
  (L903) e o link oficial. **DONO (padrão adotado, a confirmar com o dono):** fica para o Master, pelo "Editar sede".
- **Editar sede (L904).** Só Master. Formulário do sistema (modal) com os rótulos do desenho: "Endereço da sede da
  OAB/<UF> (copie da página oficial do CFOAB):" (até 255), "Telefone da seccional (opcional):" (até 40) e "Site oficial
  da seccional (https://...), opcional:" (até 255). Site aceito se começa com `http://` ou `https://`; senão fica vazio
  (antes: só `https://`). POST com CSRF, UseCase dentro de `EscopoDeAuditoriaDePlataforma::executar()` (auditoria de
  plataforma, sem escritório).
- **Mostrar ou ocultar no mapa (botão direito no chip) e o botão do cabeçalho (L902).** Filtro de seccionais visíveis:
  sem filtro todas aparecem e o botão diz "Todas visíveis"; com filtro diz "Mostrar todas". O clique no botão sempre
  limpa o filtro. Não existe "Ocultar todas". Convenção: guardado no banco, compartilhado entre os Masters.
  **DONO (padrão adotado, a confirmar com o dono):** compartilhado entre os Masters (`visivel` na seccional).
- **Tooltip do chip (L902).** "OAB/<UF> · <Estado>[ · <status> em dd/mm/aaaa] · clique para ver a sede, botão direito
  para mostrar ou ocultar no mapa" (o trecho entre colchetes só quando há divulgação registrada).
- **Marcador da seccional no mapa.** Um ponto na UF (centroide do estado no GeoJSON local), com o título da seccional
  no tooltip. **DONO (padrão adotado, a confirmar com o dono):** centroide do estado; só
  as seccionais visíveis, na cor da marca.
- **Registro de divulgação (botão direito no mapa, L902).** Por UF, guarda a cor escolhida na paleta e a informação
  {status, data}. Os 6 status do desenho: "Material enviado", "Reunião ou evento", "Em negociação", "Parceria
  firmada", "Retornar depois", "Convênio feito". Grava no banco (tabela de plataforma com UF, status, data, cor, autor
  e criação). **DONO (padrão adotado, a confirmar com o dono):** o menu de botão direito que o mapa já tinha,
  com a paleta dele (`CORES`), os 6 status e a data (padrão hoje). Grava na própria seccional (`marca_*`), não numa
  tabela de histórico.
  (Antes: 3 tipos, "Material enviado", "Reunião", "Parceria", com nota.)
- **Ponto colorido no chip ("já trabalhada", L902).** Aparece só quando a UF tem status e cor registrados; a cor é a
  escolhida para a UF na paleta, não uma cor por tipo. (Antes: cor fixa por tipo.)
- **Histórico de divulgações. DONO (padrão adotado, a confirmar com o dono).** O desenho não mostra as 5 últimas
  divulgações no cartão nem nota ou autor da divulgação; saíram da tela e do GET. A trilha de auditoria guarda cada
  marca registrada.
- **Dados para a tela.** Um GET de plataforma (`/master/dados/seccionais`) com as 27 seccionais: UF, nome, endereço,
  telefone, site, número de inscrições e a divulgação atual (status, data, cor). O painel e o iframe usam o mesmo
  endpoint.

## Fora do escopo

- Mudar a tabela de UFs, os seletores ou a contagem do mapa que já existem.
- O Quadro da Advocacia (fontes públicas), que já mostra advogados por UF.

## Arquivos e coordenação

- Não tocar: Central #192 (b5), F6 A+C (b5: contas, convites, hierarquia, perfis.js; no `painel.js` só o bloco do mapa),
  temas #191 (9b), domínio Comercial (5d).
- `master/painel.html.twig` e `master.css` são compartilhados: mudança mínima (uma URL no bloco `urls`, CSS em bloco novo
  no fim) e aviso no PR.
- Migration `Version20261020148400`: as duas tabelas e a carga das 27 UFs (só UF e nome).

## Conferência com o desenho inteiro (21/10/2026)

- O bloco aparece no modo "OAB" do seletor "quem" (terceiro modo), não em "Pessoas".
- Modo OAB: subtítulo, título "Inscrições", linhas "OAB/<UF> · <Estado>" clicáveis que abrem o cartão.
- `secN`: "N inscrição(ões) de usuários do BlueJus", o mesmo número da linha.
- `secTit`: "OAB/<UF> · Seccional <Estado>"; sede vazia: "Endereço da sede: consulte a página oficial".
- Desenho não traz endereços das sedes.
- Botão do cabeçalho: "Mostrar todas" / "Todas visíveis"; não há "Ocultar todas".
- Chip: segundo clique fecha o cartão; tooltip literal registrado.
- "Editar sede": rótulos do desenho; site aceita `http://` e `https://`.
- Divulgação: 6 status, cor da paleta por UF; sai a cor por tipo, a nota e as 5 últimas no cartão.
