# "Estados que faltam" clicáveis e a janela da OAB (desenho 05, Perfis 1.2 de 21/10/2026)

Sessão b5. Risco BAIXO/MÉDIO: só o Master (`ROLE_SUPER_ADMIN`) vê e escreve; nada de escritório.
Migration: `Version20261020148800` (número dado pela coordenação, 21/10).

Desenho: `docs/design/perfis-1.2/vs-21-10-26/05 - Perfis 1.2 - VS 21.10.26.dc.html`: lista dos que faltam L131-140,
seletor de modos L134, tabela de UFs L118, janela da OAB L481-495; lógica L877-898. Desenho conferido inteiro em
21/10/2026 (286394 bytes).

Base: as Seccionais da OAB (#196, spec `master-seccionais-oab.md`): tabela `seccional_oab` com as 27 sedes, "Editar sede",
divulgações e o GET `/master/dados/seccionais`. Esta frente reaproveita a sede e o "Editar sede" de lá.

## Estado da frente

- 21/10: spec escrita, worktree `master-oab-faltam` (base master). Implementação delegada; revisão antes do PR.
- 21/10: implementado (commit local, sem PR): `SubsecaoOab` e `NotaDaSeccional` (migration `Version20261020148800`),
  três UseCases (adicionar e remover subseção, salvar nota), três POST no `SeccionaisOabController`, GET das
  seccionais com subseções e nota por UF, janela da OAB no `painel.js` (bloco das seccionais) e estilos no
  `master-seccionais.css`. Suíte direcionada e `scripts/qa.sh` verdes. Falta: revisão e o smoke do dono na tela.
- Pendências e convenções anteriores (seletor de modos, `oabNotaEm`, `oabSecCor`, subseção, subtítulo pela linha)
  resolvidas pela conferência com o desenho inteiro (seção no fim). Antes: o subtítulo era "<UF> · N usuários no
  BlueJus" e a data da nota saía sem autor.
- 21/10, revisão do commit 61a007d9 (commit novo):
  - No Mundo: o clique mostrava o aviso "A janela da OAB vale só para os estados do Brasil." Superado: o desenho
    (L885) deixa o item sem ação e sem aviso.
  - Subtítulo pela linha ("N usuários", "N primários", "N escritórios"). Superado: o desenho não abre a janela pela
    linha (L898) e o subtítulo da janela é fixo (L889).
  - Carga das seccionais falhada: o clique tenta de novo; se falhar outra vez, o aviso de erro do painel aparece
    embaixo do total da tabela de UFs.
  - Nota: a gravação trava a linha da seccional (SELECT ... FOR UPDATE) antes de ler a nota, então duas
    gravações ao mesmo tempo ficam em fila e a última vence, sem 500 pela unicidade. A trilha mostra só
    "Campos alterados: texto" ao reescrever (os carimbos de quem e quando não contam).
- 21/10: spec conferida com o desenho inteiro. O que já foi implementado (commit 61a007d9 na worktree
  `master-oab-faltam`) precisa ser revisto contra esta conferência.
- 21/10: frente `oab-desenho-05`, migration `Version20261020149500` (a mesma das seccionais: `cidade` na subseção e
  a tabela `master_nota_cidade_oab`, uma nota por seccional e cidade). Alinhado ao desenho: pílulas Estados/Capitais,
  Capitais pelas cidades com usuário do mapa, janela do estado e da cidade com título e subtítulo do desenho, sede com
  as cores do desenho, subseções por cidade (cidade obrigatória, nome preenchido), busca do Google no vazio, nota do
  estado ou da cidade com "Nota salva em dd/mm/aaaa, hh:mm" (Brasília), "Salvar nota" fecha a janela, Mundo sem
  ação. Falta: revisão e o smoke do dono na tela.

## O que o desenho mostra

1. **Item da lista dos que faltam (L140, L880).** Cada item vira clicável (`f.ir`), com o tooltip "Ver endereços da OAB
   e escrever nota", o nome (`f.r`), o ícone de nota quando há uma (`f.nota`, title "Tem nota"), um subtítulo (`f.sub`)
   no lugar do "0" de hoje e uma seta. Grupos por região com "rótulo · N" (`g.rot`, `g.n`), como hoje.
2. **Botão e cobertura (L888).** Rótulo do botão (`faltamRot`): "Ocultar o que falta" quando aberto; fechado, "Estados
   que faltam" no Brasil e "Países que faltam" no Mundo. Tooltip (`faltamTit`): "Mostrar onde ainda não temos
   usuários". Cobertura "F de 27 (x%)".
3. **Seletor de modos (L134, L888, `faltamModos`)**, só no Brasil (`faltamBr`), entre o botão e a cobertura: pílulas
   "Estados" e "Capitais"; ativa com fundo `#fff`, cor `#0a6fa0` e sombra `0 1px 2px rgba(18,36,47,.12)`; inativa
   transparente com cor `#5b7383`.
4. **Janela da OAB (L481-495, L889-896)**, título `oabPopTit` e subtítulo `oabPopSub`, X para fechar:
   - "SECCIONAL · SEDE": "Editar" (`oabSecEditar`), endereço (`oabSecEnd`, cor `oabSecCor`), telefone (se houver),
     "Ver no mapa" (`oabMapsSec`, se houver endereço) e "Site oficial" (se houver site);
   - "SUBSEÇÕES" com "Adicionar endereço" (`oabSubAdd`); a lista (`oabSubs`: nome, endereço, telefone se houver, botão
     "Remover"); sem nenhuma: "Nenhuma subseção cadastrada. Pesquisar o endereço oficial e adicionar." (L488, link em
     "Pesquisar o endereço oficial", `oabBuscar`);
   - "NOTA EXPLICATIVA": campo de texto (placeholder "Ex.: contato com a presidente da subseção, enviar material em
     novembro") e a data da última gravação (`oabNotaEm`);
   - rodapé: "Fechar" e "Salvar nota".
5. **Linha da tabela de UFs (L118, L898)**: no modo OAB abre o cartão da sede das Seccionais (spec
   `master-seccionais-oab.md`), não esta janela.

## Contrato

- **Modo Estados (padrão, L881).** Lista os estados sem usuário no BlueJus, por região; meta "os 27 estados". Clique
  abre a janela do estado. Subtítulo do item (`f.sub`) vazio.
- **Modo Capitais (L879-882).** Lista as 27 capitais que ainda não têm usuário naquela UF (comparação do nome da cidade
  sem acento e sem caixa), por região, em ordem alfabética; item = nome da capital, subtítulo = UF; meta "as 27
  capitais"; cobertura "F de 27 (x%)". Clique abre a janela da cidade. **Decidido (21/10/2026, pela recomendação):** as cidades
  com usuário vêm da lotação ou do cadastro, a mesma base do mapa (`G.C` do MapaDeAtuacao); a nota por cidade e a
  janela da cidade entram neste escopo (o desenho mostra as duas).
- **Mundo (L885).** O item dos que faltam não tem ação ao clicar (sem aviso), subtítulo vazio e sem nota. **DONO (padrão adotado, a confirmar com o dono):**
  sem ação no Mundo. (Antes: aviso "A janela da OAB vale só para os estados do Brasil.")
- **Ícone de nota (`f.nota`, L880).** Aparece quando existe nota daquele estado (modo Estados) ou daquela cidade (modo
  Capitais).
- **Título e subtítulo da janela (L889).** Estado: título com o nome do estado (ex. "Bahia"), subtítulo "Seccional
  OAB/<UF> e subseções cadastradas". Cidade: título "<Cidade> · <UF>", subtítulo "Subseção da OAB e seccional
  OAB/<UF>". (Antes: "OAB Seccional <Estado>" e "<UF> · sem usuários no BlueJus".)
- **SECCIONAL · SEDE (L889-890).** Lê a sede da `seccional_oab` (#196). Sem endereço: "Endereço ainda não cadastrado"
  em `#9aabb7`; com endereço, em `#243845` (`oabSecCor`). "Editar" grava pelo MESMO UseCase do "Editar sede" do #196,
  com os rótulos desta janela: "Endereço da sede da OAB/<UF>:", "Telefone (opcional):", "Site oficial (https://...),
  opcional:"; site aceito se começa com `http://` ou `https://`, senão vazio. "Ver no mapa": link do Google Maps com o
  endereço (`https://www.google.com/maps/search/?api=1&query=<endereço>`), nova aba, `rel="noopener"`. "Site oficial":
  o site da sede.
- **SUBSEÇÕES (L893-894).** Tabela de plataforma: UF (FK lógica para `seccional_oab`), cidade (obrigatória), nome
  (opcional), endereço (até 255, obrigatório), telefone (até 40, opcional), autor, criação. Sem site e sem edição: só
  adicionar e remover. "Adicionar endereço" abre um formulário do sistema na própria janela; na janela do estado o
  primeiro campo é "Cidade da subseção:"; depois "Nome (ex.: Subseção de <cidade>):" já preenchido com "Subseção de
  <cidade>", "Endereço completo:" e "Telefone (opcional):". Na janela da cidade a cidade já vem dela. Nome vazio
  aparece como "Subseção <cidade>". Na janela do estado a lista junta as subseções de todas as cidades da UF; na da
  cidade, só as daquela cidade. "Remover" pede confirmação "Remover este endereço?" (modal do painel) e apaga a linha.
  (Antes: nome obrigatório, sem cidade.)
- **"Pesquisar o endereço oficial" (L895).** Busca no Google em nova aba: "OAB seccional <Estado> endereço" na janela
  do estado, "OAB subseção <Cidade> <Estado> endereço" na da cidade. (Antes: link do CFOAB.)
- **NOTA EXPLICATIVA (L896).** Uma nota por estado ou por cidade. Convenção: no banco, compartilhada entre os Masters
  (texto até 1000, `atualizada_em`, `atualizada_por`). "Salvar nota" grava e fecha a janela; texto vazio apaga a nota.
  A data mostrada é "Nota salva em dd/mm/aaaa, hh:mm", sem autor. **DONO (padrão adotado, a confirmar com o dono):** nota e subseção
  no banco, compartilhadas entre os Masters; texto até 1000, cidade e nome até 120, endereço até 255, telefone até 40.
- **Linha da tabela de UFs (`u.ir`, L898).** No modo OAB abre o cartão da sede das Seccionais (cursor de mão só nesse
  modo); nos outros modos e no Mundo, sem ação. (Antes: abria esta janela no Brasil.)
- **Dados.** O GET `/master/dados/seccionais` do #196 passa a trazer, por UF, as subseções (com cidade) e as notas (do
  estado e das cidades, com data), para a janela abrir sem outra ida ao servidor. Escrita: POST de plataforma com CSRF,
  um por ação (adicionar subseção, remover subseção, salvar nota), UseCase dentro de
  `EscopoDeAuditoriaDePlataforma::executar()`, como o "Editar sede".
- **Tabelas de plataforma** (sem tenant): entram em `FORA_DO_ESCOPO` do `TenantAwareCoberturaTest` (somar o
  `MAX_FORA_DO_ESCOPO`) e nas não auditáveis ou auditadas de plataforma, no mesmo padrão das tabelas do #196.
- Texto da tela sem travessão.

## Testes obrigatórios

- Master adiciona (com cidade) e remove subseção e salva a nota do estado e da cidade; cidade ou endereço vazios
  recusados (422) sem gravar; nota vazia apaga.
- Não Master: 403 em todos os POST e no GET; CSRF errado: 403.
- O GET devolve subseções e nota por UF.
- Tela: o item dos que faltam no Brasil é clicável com o tooltip do desenho; pílulas "Estados" e "Capitais"; a janela
  tem as três seções e os botões (arranjo por combinador de filho direto); títulos e subtítulos do desenho; "Ver no
  mapa" só com endereço; texto vazio de subseções igual ao desenho; "Nota salva em dd/mm/aaaa, hh:mm".
- `TenantAwareCoberturaTest`, `AuditavelCoberturaTest`, arquitetura e travessão verdes.

## Fora do escopo

- A carga dos endereços das sedes (DONO, já registrada no #196).
- Mudar a contagem do mapa, os grupos dos que faltam ou a meta.

## Conferência com o desenho inteiro (21/10/2026)

- Seletor "Estados" / "Capitais" entra no escopo (modo Capitais com janela de cidade).
- Rótulos do botão e tooltip registrados.
- Título e subtítulo da janela pelos textos do desenho; sede vazia "Endereço ainda não cadastrado".
- "Editar" da janela com rótulos próprios; site aceita `http://` e `https://`.
- Subseção por cidade (cidade e endereço obrigatórios, nome opcional, sem site, sem edição).
- "Pesquisar o endereço oficial" vira busca no Google.
- Nota por estado ou cidade; "Salvar nota" fecha a janela; data "Nota salva em dd/mm/aaaa, hh:mm".
- `f.sub` vazio (estado) ou UF (capital); some "N subseções" e "sem endereço".
- No Mundo o item não tem ação nem aviso.
- Linha da tabela de UFs abre o cartão das Seccionais no modo OAB, não esta janela.
