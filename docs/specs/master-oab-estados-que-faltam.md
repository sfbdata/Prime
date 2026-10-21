# "Estados que faltam" clicáveis e a janela da OAB (desenho 05, Perfis 1.2 de 21/10/2026)

Sessão b5. Risco BAIXO/MÉDIO: só o Master (`ROLE_SUPER_ADMIN`) vê e escreve; nada de escritório.
Migration: `Version20261020148800` (número dado pela coordenação, 21/10).

Desenho: `docs/design/perfis-1.2/vs-21-10-26/05 - Perfis 1.2 - VS 21.10.26.dc.html`: lista dos que faltam L131-140,
seletor de modos L134, tabela de UFs L118, janela da OAB L481-495. **O arquivo chegou truncado em 256 KiB:** os
templates estão inteiros, mas a lógica (`faltamGrupos`, `faltamModos`, `f.sub`, `u.ir`, `oabPop*`, `oabSubs`,
`oabNota*`, `oabBuscar`) ficou no trecho que falta. O que é inferência está marcado **DONO** e segue a convenção do
sistema até o arquivo inteiro chegar.

Base: as Seccionais da OAB (#196, spec `master-seccionais-oab.md`): tabela `seccional_oab` com as 27 sedes, "Editar sede",
divulgações e o GET `/master/dados/seccionais`. Esta frente reaproveita a sede e o "Editar sede" de lá.

## Estado da frente

- 21/10: spec escrita, worktree `master-oab-faltam` (base master). Implementação delegada; revisão antes do PR.
- 21/10: implementado (commit local, sem PR): `SubsecaoOab` e `NotaDaSeccional` (migration `Version20261020148800`),
  três UseCases (adicionar e remover subseção, salvar nota), três POST no `SeccionaisOabController`, GET das
  seccionais com subseções e nota por UF, janela da OAB no `painel.js` (bloco das seccionais) e estilos no
  `master-seccionais.css`. Suíte direcionada e `scripts/qa.sh` verdes. Falta: revisão e o smoke do dono na tela.
- **Pendente (DONO, espera o arquivo inteiro do desenho):** o seletor de modos (L134, `faltamModos`), não
  implementado; formato exato de `oabNotaEm` e `oabSecCor`; se a subseção guarda site e se o Master pode editá-la.
- **Convenções tomadas (DONO, revisar com o arquivo inteiro):** subtítulo da janela aberta pela linha da UF
  "<UF> · N usuários no BlueJus" (ou "N escritórios" na visão Escritórios; o estado que falta na visão Escritórios
  diz "sem escritórios"); no Mundo, o item dos que faltam fica sem tooltip e sem a seta; a data da nota sai sem o
  autor (o autor vem no GET).

## O que o desenho mostra

1. **Item da lista dos que faltam (L140).** Cada estado vira clicável (`f.ir`), com o tooltip "Ver endereços da OAB e
   escrever nota", o nome (`f.r`), o ícone de nota quando há uma (`f.nota`, title "Tem nota"), um subtítulo (`f.sub`)
   no lugar do "0" de hoje e uma seta. Grupos com "rótulo · N" (`g.rot`, `g.n`), como hoje.
2. **Janela da OAB (L481-495)**, título `oabPopTit` e subtítulo `oabPopSub`, X para fechar:
   - "SECCIONAL · SEDE": "Editar" (`oabSecEditar`), endereço (`oabSecEnd`, cor `oabSecCor`), telefone (se houver),
     "Ver no mapa" (`oabMapsSec`, se houver endereço) e "Site oficial" (se houver site);
   - "SUBSEÇÕES" com "Adicionar endereço" (`oabSubAdd`); a lista (`oabSubs`: nome, endereço, telefone se houver, botão
     "Remover"); sem nenhuma: "Nenhuma subseção cadastrada. Pesquisar o endereço oficial e adicionar." (`oabBuscar`);
   - "NOTA EXPLICATIVA": campo de texto (placeholder "Ex.: contato com a presidente da subseção, enviar material em
     novembro") e a data da última gravação (`oabNotaEm`);
   - rodapé: "Fechar" e "Salvar nota".
3. **Seletor de modos (L134, `faltamModos`)**, só no Brasil (`faltamBr`), entre o botão e a cobertura: pílulas no padrão
   dos outros seletores do painel (fundo branco e sombra no ativo).
4. **Linha da tabela de UFs (L118)** clicável (`u.ir`, cursor `u.cur`).

## Contrato

- **Clique no estado que falta** abre a janela da OAB daquela UF. Só no Brasil; no Mundo, o item segue sem clique
  (**DONO**: o desenho mostra o mesmo template nos dois, mas a janela é de seccional, que só existe no Brasil).
- **Título e subtítulo da janela. DONO.** Convenção: título "OAB Seccional <Estado>" (o nome da `seccional_oab`),
  subtítulo "<UF> · sem usuários no BlueJus" para o estado que falta.
- **SECCIONAL · SEDE.** Lê a sede da `seccional_oab` (#196). Sem endereço: "Endereço não cadastrado. Confira no CFOAB."
  em cinza (`oabSecCor`), como o cartão do #196. "Editar" abre o MESMO "Editar sede" do #196 (sem duplicar UseCase).
  "Ver no mapa": link do Google Maps com o endereço (`https://www.google.com/maps/search/?api=1&query=<endereço>`),
  nova aba, `rel="noopener"`. "Site oficial": o site da sede.
- **SUBSEÇÕES.** Tabela de plataforma nova: UF (FK lógica para `seccional_oab`), nome (até 120, obrigatório),
  endereço (até 255, obrigatório), telefone (até 40, opcional), autor, criação. "Adicionar endereço" abre um formulário
  na própria janela (nome, endereço, telefone); "Remover" pede confirmação no padrão do painel e apaga a linha.
  "Pesquisar o endereço oficial" é o mesmo link do CFOAB do #196
  (https://www.oab.org.br/institucionalconselhofederal/asiseccional), nova aba. **DONO:** se a subseção guarda site e
  se o Master pode editar (o desenho só mostra adicionar e remover).
- **NOTA EXPLICATIVA.** Uma nota por UF, compartilhada entre os Masters (é anotação de trabalho da plataforma, como as
  divulgações): texto até 1000, `atualizada_em`, `atualizada_por`. "Salvar nota" grava; texto vazio apaga a nota.
  A data mostrada é "Atualizada em dd/mm/aaaa" (**DONO** o formato exato).
- **Ícone de nota e subtítulo do item (`f.nota`, `f.sub`). DONO.** Convenção: o ícone aparece quando a UF tem nota; o
  subtítulo mostra "N subseções" quando há alguma, senão "sem endereço" quando a sede não tem endereço, senão vazio.
  Some o "0" de hoje.
- **Seletor de modos (`faltamModos`). DONO**, rótulos e efeito no trecho truncado. NÃO implementar até o arquivo
  inteiro: registrar como pendência na frente. (Inventar o filtro seria decidir o desenho.)
- **Linha da tabela de UFs (`u.ir`). DONO**, ação no trecho truncado. Convenção: no Brasil, abre a mesma janela da OAB
  daquela UF (cursor de mão); no Mundo, sem clique.
- **Dados.** O GET `/master/dados/seccionais` do #196 passa a trazer, por UF, as subseções e a nota (com data e autor),
  para a janela abrir sem outra ida ao servidor. Escrita: POST de plataforma com CSRF, um por ação (adicionar subseção,
  remover subseção, salvar nota), UseCase dentro de `EscopoDeAuditoriaDePlataforma::executar()`, como o "Editar sede".
- **Tabelas de plataforma** (sem tenant): entram em `FORA_DO_ESCOPO` do `TenantAwareCoberturaTest` (somar o
  `MAX_FORA_DO_ESCOPO`) e nas não auditáveis ou auditadas de plataforma, no mesmo padrão das tabelas do #196.
- Texto da tela sem travessão.

## Testes obrigatórios

- Master adiciona e remove subseção e salva a nota (com data e autor); campos inválidos recusados (422) sem gravar;
  nota vazia apaga.
- Não Master: 403 em todos os POST e no GET; CSRF errado: 403.
- O GET devolve subseções e nota por UF.
- Tela: o item dos que faltam no Brasil é clicável com o tooltip do desenho; a janela tem as três seções e os botões
  (arranjo por combinador de filho direto); "Ver no mapa" só com endereço; texto vazio de subseções igual ao desenho.
- `TenantAwareCoberturaTest`, `AuditavelCoberturaTest`, arquitetura e travessão verdes.

## Fora do escopo

- Seletor de modos (espera o arquivo inteiro).
- A carga dos endereços das sedes (DONO, já registrada no #196).
- Mudar a contagem do mapa, os grupos dos que faltam ou a meta.
