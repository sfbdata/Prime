# Voto: ocorrências e avaliações do conselho (W2-7b)

Risco **MÉDIO** (dá ao nível Visualizar uma escrita por papel). Decisões do Coordenador em 16/11/2026
(`bluejus-coordenacao/voto-w27b-avaliacoes-perguntas-1611.md`, as 8 recomendações aceitas). Base: a equipe por
assembleia (`docs/specs/voto-equipe-e-master-1611.md`, seção 1.2). Desenho: `docs/design/voto/export-2026-11-16/`
("18 - Voto - VS 08.11.26.dc.html": Lista de aptos H249/H462, Avaliações H273/H469, cédula H449, Master H245/H466,
relatório H297/H401; `bj-voto-nucleo.js` N97, N119-120; `ESPEC_VOTO.md` E231-232).

Fora desta etapa (pendente com nome): **consulta de situação no portal** (csRes, H485), levada ao pacote jurídico.

## 1. Dado (migration `Version20261117050000`, a única)

Tabela `voto.ocorrencia_da_unidade`, só acrescenta (nunca sobrescreve, ao contrário do protótipo):

| coluna | regra |
|---|---|
| id, tenant_id, assembleia_id, unidade_id | FK; CASCADE pela assembleia e pela unidade |
| texto | até 80, obrigatório |
| origem | `importacao` ou `manual` |
| registrada_em, registrada_por_nome | quando e quem |
| avaliacao | nula (sem avaliação), `pendente`, `mantida`, `tornada_apta` |
| decidida_em, decidida_por_nome, justificativa (até 160) | preenchidos na decisão |

Índice único parcial: uma avaliação `pendente` por unidade. Entra em `PurgarEscritorioUseCase` (filha antes da
unidade) e em `NAO_AUDITAVEIS` do `AuditavelCoberturaTest` (o rastro é a trilha própria, seção 4).

Na assembleia: `hash_da_lista` (sha256, 64) e `lista_travada_em`, nulos até a trava.

## 2. Regras

- **Registrar ocorrência**: a qualquer momento até a assembleia acabar. Pessoa não apta abre avaliação `pendente`;
  pessoa apta só registra (sem avaliação). Uma nova ocorrência com pendente aberto: recusa ("já há avaliação
  pendente"; texto do desenho se houver).
- **Importação**: coluna 8 `ocorrencia` (opcional) do leitor da lista; entra com `origem = importacao` e sem
  avaliação (N97).
- **Decidir**: `Manter inaptidão` ou `Tornar apto(a)`, justificativa obrigatória (até 160, "Informe a
  justificativa."). Decisão tomada não se refaz. `Tornar apto(a)` só enquanto nenhuma pauta da assembleia estiver
  encerrada; depois, "Votação encerrada: a aptidão não pode mais ser alterada.". `Manter` vale sempre.
  `Tornar apto(a)` muda a unidade por método rico (`tornarAptaPeloConselho()`), sem tocar em `situacao`.
- **Hash da lista**: calculado e gravado quando a lista trava (a 1ª pauta sai de Aguardando; mesmo ponto de
  `podeAlterarUnidades`), sha256 das unidades em ordem estável (bloco, identificação, aptidão, fração). A decisão do
  conselho **não** recalcula: a tela mostra "Hash da lista: ..." e "N alteração(ões) por decisão do conselho." (H462,
  E232), contadas pelas ocorrências `tornada_apta` decididas depois de `lista_travada_em`.

## 3. Quem pode (ponto único em `AcessoAAssembleia`)

Continua teto: nada aqui dá acesso a quem a guarda do módulo recusa.

| | sem equipe própria | com equipe própria |
|---|---|---|
| registrar ocorrência | quem tem nível Editar | comissão (Editar) e a Administração |
| decidir avaliação | só a Administração | conselho e a Administração |
| ver a aba Avaliações | a Administração | conselho e a Administração |

A decisão do conselho é a **única** escrita do nível Visualizar: métodos `podeRegistrarOcorrencia` e
`podeDecidirAvaliacao` em `AcessoAAssembleia`, e a rota da decisão entra em `ROTAS_DO_VISUALIZAR` da barreira com o
motivo, conferindo o papel no UseCase. Re-revisão do teto antes de integrar.

## 4. Telas e trilha

- Unidades e links (Lista de aptos): busca "Procurar por nome ou unidade..." e campo "Ocorrência, ex.: cadastro
  desatualizado" + [Registrar], "Ocorrência registrada." (textos literais do desenho); hash e contador.
- Sub-aba "Avaliações" (H273/H469): título, texto, vazio "Nenhuma avaliação registrada.", itens com chips
  Pendente/Tornado apto/Mantida e cores do desenho, pendentes primeiro, campo "Justificativa da decisão",
  [Manter inaptidão] [Tornar apto(a)], "Decisão registrada.".
- Cédula da unidade não apta: ocorrência e "(em avaliação pelo conselho)" (H449).
- Relatório/ata: seção "Ocorrências e avaliações de aptidão" (H297/H401) se houver ocorrência.
- Trilha (`audit_log`, molde `RegistrarAuditoriaDaRelacaoDeVotantesUseCase`): "Ocorrência registrada",
  "Inaptidão alterada pelo conselho", "Inaptidão mantida pelo conselho", sem dado do votante além do id da unidade.

## 5. Master

KPIs "Ocorrências", "Avaliações pendentes", "Inaptidões mantidas", "Tornados aptos" e as colunas
`ocorrencias;pendentes;mantidas;tornados_aptos` no CSV, contados **por assembleia** (não por pauta), fora rascunho e
simulação; "· N ocorrência(s)" na linha.

## 6. Testes obrigatórios

Registrar (apta sem avaliação, não apta pendente, importação sem avaliação); decidir (justificativa, manter,
tornar apto antes e depois de pauta encerrada, não refaz); hash gravado na trava e não recalculado; contador;
quem pode (tabela da seção 3, inclusive auditoria/fiscal recebendo 403 na decisão e conselho recebendo 403 no
registro); outro escritório 404; purga e migration; Master.
