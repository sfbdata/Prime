---
tipo: novidade
---
## Changelog
- Voto: tabela `voto.ocorrencia_da_unidade` (migration `Version20261117050000`), só acrescenta: ocorrência com texto até 80, origem (importação ou manual), autor, avaliação (pendente, mantida, tornada apta), decisão e justificativa até 160. Índice único parcial: uma avaliação pendente por unidade. Entra na purga e em `NAO_AUDITAVEIS`.
- `Assembleia` ganha `hash_da_lista` (sha256) e `lista_travada_em`, gravados quando a primeira pauta sai de Aguardando; a decisão do conselho não recalcula.
- Casos de uso `RegistrarOcorrenciaUseCase`, `DecidirAvaliacaoUseCase` e `ObterAvaliacoesDaAssembleiaUseCase`, trilha em `audit_log` ("Ocorrência registrada", "Inaptidão alterada pelo conselho", "Inaptidão mantida pelo conselho") só com ids.
- `AcessoAAssembleia`: `podeRegistrarOcorrencia`, `podeDecidirAvaliacao`, `podeVerAvaliacoes`. A decisão do conselho é a única escrita do nível Visualizar (rota em `ROTAS_DO_VISUALIZAR`, papel conferido também no caso de uso); funções Twig `voto_decide_avaliacao`. `docs/AUTORIZACAO.md` atualizado.
- Importação da lista aceita a 8ª coluna "Ocorrência" (sem abrir avaliação).
- Telas: sub-aba "Avaliações", busca, hash e contador na lista de unidades, ocorrência na cédula da unidade não apta e seção na ata.
- Master: KPIs "Ocorrências", "Avaliações pendentes", "Inaptidões mantidas" e "Tornados aptos" por assembleia, colunas `ocorrencias;pendentes;mantidas;tornados_aptos` no CSV e "N ocorrência(s)" em cada linha.

## Para o cliente
- No Voto, quem cuida da assembleia pode anotar uma ocorrência em qualquer unidade (por exemplo, "cadastro desatualizado"). Se a unidade não está apta a votar, a anotação abre um pedido de avaliação para o conselho.
- Nova aba "Avaliações": o conselho vê os pedidos, escreve a justificativa e decide entre manter a inaptidão ou tornar a unidade apta. A decisão fica registrada e não se refaz. Depois que uma votação encerra, só dá para manter.
- A lista de unidades mostra uma assinatura da lista (o "hash") e quantas alterações o conselho fez depois que ela travou. A ata traz a seção de ocorrências e avaliações.
- A planilha de importação das unidades aceita uma coluna opcional de ocorrência.
- No Painel do Master, as estatísticas do Voto mostram os totais de ocorrências e avaliações, e o arquivo CSV traz as mesmas colunas.
- A unidade que não está apta vê, na própria cédula, a ocorrência anotada e se ela está em avaliação pelo conselho.
