# Seccionais da OAB no mapa "Onde estamos atuando" (desenho 05, Perfis 1.2 de 21/10/2026, F8)

Sessão f6 (corte da b5). Risco BAIXO/MÉDIO: só o Master (`ROLE_SUPER_ADMIN`) vê e escreve.

Desenho: `docs/design/perfis-1.2/vs-21-10-26/05 - Perfis 1.2 - VS 21.10.26.dc.html`, template L116-127 (bloco `ehOab`
abaixo da tabela de UFs). **O arquivo chegou truncado em 256 KiB:** o template está inteiro, mas a lógica (`ehOab`,
`secChips`, `secEditar`, o registro de divulgação no mapa e os dados das 27 sedes) ficou no trecho que falta. O dono vai
mandar o arquivo inteiro. Até lá, o que é inferência está marcado **DONO** e segue a convenção do sistema.

## Estado da frente

- 21/10: spec escrita, worktree `master-seccionais-oab` (base master). Migration reservada `Version20261020148400`
  (pedir outra se o master passar dela na hora do PR).

## O que o desenho mostra (template L116-127)

Embaixo da tabela de UFs do mapa, um bloco "SECCIONAIS VISÍVEIS":

1. Cabeçalho "SECCIONAIS VISÍVEIS" com o botão "Mostrar todas" (`secTodas`/`secTodasTxt`; o texto alterna).
2. Grade com os 27 chips de UF (`secChips`, 34px de largura mínima, 22px de altura):
   - clique: abre o cartão da sede da seccional (`x.ver`);
   - botão direito: mostra ou oculta a seccional no mapa (`x.tgCtx`);
   - ponto colorido no chip: seccional já trabalhada (`x.mkTem`, `x.mkCor`).
3. Cartão da sede (`secTem`): faixa azul com `bi-bank`, o título (`secTit`) e o X; linhas `bi-people` com "N usuários"
   (`secN`), `bi-geo-alt` com o endereço (`secEnd`), `bi-telephone` com o telefone (`secTel`, só se houver) e o link
   "Site da seccional" (`secSite`, só se houver); botões "Endereço oficial (CFOAB)" (link fixo
   https://www.oab.org.br/institucionalconselhofederal/asiseccional) e "Editar sede" (só Master, `souMasterB`).
4. Nota literal: "Clique numa seccional para ver a sede. Botão direito na lista mostra ou oculta no mapa; botão direito
   no mapa registra a divulgação (material enviado, reunião, parceria). O ponto colorido indica seccional já trabalhada.
   Fonte: Conselho Federal da OAB."

## Contrato

- **Quando o bloco aparece (`ehOab`). DONO.** A condição está no trecho truncado. Convenção até lá: aparece no mapa do
  Brasil, na visão "Pessoas" (o mapa da advocacia), e some no Mundo e em "Escritórios".
- **"N usuários" (`secN`).** Pessoas com vínculo ativo inscritas naquela seccional, pela tabela `inscricao_oab`
  (principal e suplementar, `COUNT(DISTINCT user_id)` por UF). Não é a contagem da tabela de UFs (essa localiza a pessoa
  pela lotação primeiro). Texto: "1 usuário" / "N usuários". **DONO:** se conta só inscrição ativa ou todas.
- **Dados das sedes.** Tabela de plataforma com as 27 seccionais (UF, nome, endereço, telefone, site). A carga da
  migration grava só a UF e o nome ("OAB Seccional <Estado>"); endereço, telefone e site ficam vazios. Não inventar
  endereço: o cartão mostra "Endereço não cadastrado. Confira no CFOAB." e o link oficial. **DONO:** a carga dos
  endereços (do arquivo inteiro do desenho ou da lista pública do CFOAB), que o Master também pode fazer pelo "Editar sede".
- **Editar sede.** Só Master. Janela com endereço (até 255), telefone (até 40) e site (só `https://`, até 255). POST com
  CSRF, UseCase dentro de `EscopoDeAuditoriaDePlataforma::executar()` (auditoria de plataforma, sem escritório).
- **Mostrar ou ocultar no mapa (botão direito no chip) e "Mostrar todas".** Conveniência de quem olha: guardado no
  navegador (`localStorage`, com try/catch), não no banco. Oculta o marcador da seccional no mapa. Com tudo visível, o
  botão diz "Ocultar todas"; com alguma oculta, "Mostrar todas". **DONO:** se deve ser compartilhado entre Masters.
- **Marcador da seccional no mapa.** Um ponto na UF (centroide do estado no GeoJSON local; **DONO:** a posição exata da
  sede, que depende de coordenada que o desenho não mostra), com o título da seccional no tooltip.
- **Registro de divulgação (botão direito no mapa).** O menu de botão direito que já existe no `mapa-brasil.js` (paleta de
  cores em `localStorage`) ganha, em cima das cores, "Registrar divulgação" com os três tipos do desenho: "Material
  enviado", "Reunião", "Parceria", mais uma nota opcional (até 500) e a data (hoje por padrão). Grava no banco: tabela de
  plataforma com UF, tipo, data, nota, autor e criação. A paleta de cores continua como está (marca visual local).
  **DONO:** os campos da divulgação além desses três tipos.
- **Ponto colorido no chip ("já trabalhada").** A seccional com ao menos uma divulgação registrada tem o ponto, na cor do
  tipo da última (Material enviado azul `#0a6fa0`, Reunião âmbar `#b7791f`, Parceria verde `#2f855a`; **DONO**: cores
  do trecho truncado). O cartão da sede lista as últimas 5 divulgações (tipo, data, autor, nota).
- **Dados para a tela.** Um GET de plataforma (`/master/dados/seccionais`) com as 27 seccionais: UF, nome, endereço,
  telefone, site, N usuários, última divulgação e as 5 últimas. O painel e o iframe usam o mesmo endpoint.

## Fora do escopo

- Mudar a tabela de UFs, os seletores ou a contagem do mapa que já existem.
- O Quadro da Advocacia (fontes públicas), que já mostra advogados por UF.

## Arquivos e coordenação

- Não tocar: Central #192 (b5), F6 A+C (b5: contas, convites, hierarquia, perfis.js; no `painel.js` só o bloco do mapa),
  temas #191 (9b), domínio Comercial (5d).
- `master/painel.html.twig` e `master.css` são compartilhados: mudança mínima (uma URL no bloco `urls`, CSS em bloco novo
  no fim) e aviso no PR.
- Migration `Version20261020148400`: as duas tabelas e a carga das 27 UFs (só UF e nome).
