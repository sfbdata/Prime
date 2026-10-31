# Central de documentos + Controladoria da pasta

Risco: A e B BAIXO/MÉDIO; **C e D ALTO na prática** (arquivo físico compartilhado: erro apaga documento de cliente).
Pedido do Samuel (31/10/2026), aprovado pelo Coordenador. **Etapa 1: só esta spec, sem código.**

Fonte: `docs/design/redesign-31-10-26/bj-central-docs.js` (protótipo em localStorage) e a tela
`02 - EXPEDIENTES - VS 31.10.26.dc.html` (carrega o script; botão flutuante "Controladoria" no canto
inferior esquerdo da pasta, painel lateral com três abas: Alertas, Documentos, Cadastro da pasta).

## O que o protótipo promete

1. **Código do documento** `DOC-XXXX-XXXX-XXXX`, calculado do conteúdo (SHA-256 + tamanho + escritório). Arquivo
   idêntico enviado de novo, com outro nome, vira só um vínculo e não ocupa espaço novo. Une **somente com 100% de
   igualdade**; no servidor real, comparação byte a byte antes de unir.
2. **Vincular** um documento a outra pasta sem baixar e subir de novo.
3. **Alertas da pasta**: duas pastas no mesmo contrato/unidade, titular do extrato diferente do réu, prescrição
   próxima, metas (prazos) vencidas, pasta parada. Níveis alta, média, baixa; contador no botão (só alta e média).
4. **Cadastro com pendência**: CPF e qualificação do réu; salva mesmo sem e-mail/endereço, fica "com pendência".
5. Extras do protótipo (fora desta frente, ver "Fora de escopo"): detecção de assinatura digital, compactação,
   "varredura a cada 10 min", economia de espaço.

## O que já existe no sistema (levantamento 31/10)

| Peça do protótipo | Já existe | Lacuna |
|---|---|---|
| Hash do arquivo | `pasta_documento.sha256` (char 64, nullable, índice `(tenant_id, sha256)`), `Sha256DeArquivo`, calculado em `UploadPecaUseCase` **depois** da compressão; backfill `CalcularHashDosDocumentosCommand` | acervo antigo sem hash; sem tamanho na chave; sem código `DOC-` |
| Duplicado | `PastaDocumentoRepository::comOMesmoConteudo`, `ListarDuplicadosDoDocumentoUseCase` (só **avisa**, em `PeticionarController`) | não une, não compara byte a byte |
| Assinatura digital, páginas, compressão | `DetectorDeAssinaturaDigital`, `ContadorDePaginasDePdf`, `CompressaoDeArquivoArmazenado` (regrava na mesma chave) | nada |
| Cópia de documento | `CopiarDocumentosDaPastaUseCase` (copia **bytes**, arquivo novo, tetos 2000 docs) | não copia para outra pasta sem reenviar |
| Vínculo sem arquivo próprio | só em cliente: `ClienteDocumento.pasta_documento_id` (CASCADE, purga já trata) | não existe pasta para pasta |
| Lixeira | `excluido_em` (30 dias), `PurgarLixeiraUseCase` apaga linha + arquivo | hoje 1 documento = 1 arquivo |
| Cota | `ConsumoDeArmazenamentoRepository`: soma `tamanho_bytes` **lógico por linha** (lixeira não conta); bloqueio em `ArmazenamentoComCota` (só `NovoArquivo`) | compartilhar conta 2x hoje |
| R2 | `ArmazenamentoEspelhado` (disco é a verdade, outbox com SHA, **exclusão vira evento, nada sai do R2**) | pressupõe 1 chave = 1 dono |
| Pasta parada | `ColetorDePastasParadas`, `AnalisadorDePastasParadas` (30/60/90 dias; Crítico/Atenção/Acompanhar) | só relatório, não alerta na pasta |
| Sem processo / pendências / prazos | `AnalisadorDePastasSemProcesso`, `PastaPendenciasOutput`, `PastaPrazo` | idem |
| Metas vencidas | `Tarefa` com prazo; `PastaMetasResumoOutput` já calcula "atrasadas" (mesma regra da lista) | reaproveitar |
| Prescrição | só em Cobrança (`CalculadoraPrescricao`) | **não há campo nem cálculo na pasta** |
| Contrato / unidade | `RelatorioLinha.unidade` (cobrança) | **não há na pasta** |
| Titular do extrato | não encontrado | **não existe** |
| Réu | `PastaReu` (nome, documento char 14, ordem) + `GerenciarReusDaPastaUseCase` | sem qualificação, e-mail, endereço, nem noção de pendência |
| Permissão | `RESOURCE_PASTA` view/edit via `PermissionChecker`; cadeado `AcessoAPasta` | nada a criar |

## Fatias (ordem de menor risco, um PR cada, uma migration por vez)

### A. Alertas da pasta (somente leitura, sem migration)

- `AlertasDaPasta` (Service no domínio Pasta): devolve lista `{nivel, titulo, detalhe, sugestao, origem}` a partir de
  **regras independentes** (uma classe por regra, mais fácil testar e acrescentar).
- Entram já, com dado existente: **metas vencidas** (reusa a regra de `PastaMetasResumoOutput`), **prazos vencidos**
  (`PastaPrazo`), **pasta parada** (reusa os limites 30/60/90 do `AnalisadorDePastasParadas`, sem criar limite novo),
  **pasta sem processo** (já existe analisador).
- As regras **dependentes de campo novo** (mesmo contrato/unidade, titular do extrato diferente do réu, prescrição)
  ficam no motor mas **só ligam na fatia B**, quando o dado existir. Desvio do pedido original, avisado ao Coordenador:
  ligar agora exigiria criar campo, o que é migration.
- Tela (desenho manda): botão flutuante "Controladoria" no canto inferior esquerdo, contador (alta + média), painel
  lateral nos componentes do desenho (não os do sistema). Aba sem função não vai para a tela: A entrega só a aba
  Alertas, e o painel nasce pronto para receber Cadastro da pasta (B) e Documentos (C/D).
- Permissão: `RESOURCE_PASTA` view. Pasta trancada pelo cadeado: não mostra (o alerta entregaria dado).
- Testes: unit por regra; functional do endpoint do painel (tenant, cadeado, sem alerta); arranjo por filho direto.

### B. Cadastro com pendência do réu + campos da Controladoria (1 migration, risco MÉDIO)

- `PastaReu` ganha `qualificacao`, `email`, `endereco` (todos opcionais). CPF já existe (`documento`), validar com o
  algoritmo de CPF (protótipo `valCPF`), CNPJ para réu pessoa jurídica.
- `Pasta` ganha `contrato`, `unidade`, `titular_extrato` (texto) e `prescricao_em` (data), todos opcionais.
- **Pendência é derivada, não gravada**: "cadastro incompleto" = réu sem CPF ou sem qualificação. Sem flag para
  desatualizar. Salvar nunca é bloqueado por falta de e-mail/endereço (regra do protótipo).
- Liga as regras de A: mesmo contrato/unidade **dentro do mesmo escritório** (comparação sem acento, sem caixa, com
  espaços colapsados), titular do extrato ≠ réu (mesma normalização), prescrição em ≤ 90 dias (alta se ≤ 30 ou vencida).
- Auditoria: `Pasta` e `PastaReu` já são `Auditavel`; a edição entra no `audit_log` e no Registro como as demais.
- Não mistura com a conferência de cadastro do Cliente (`cliente-inteligencia-cadastral.md`): são coisas diferentes
  (réu da pasta, não cliente).

### C. Código do documento e deduplicação segura (1 migration, ALTO)

**Princípio:** junta só quando é igual de verdade; na dúvida, guarda os dois. Nunca perde arquivo.

- Nova tabela `arquivo_armazenado` (tenant, `codigo` DOC-XXXX-XXXX-XXXX único por escritório, `sha256`, `tamanho_bytes`,
  `chave_fisica`). `pasta_documento.arquivo_id` (FK nullable). Linha sem `arquivo_id` segue com arquivo próprio
  (acervo antigo continua funcionando; migração do legado é opcional e posterior, por comando, nunca na migration).
- **Quando une** (tudo isso, nesta ordem): mesmo escritório; `sha256` igual; `tamanho_bytes` igual; **comparação
  byte a byte** do arquivo novo contra o armazenado, em streaming. Qualquer diferença ou erro de leitura: guarda
  arquivo novo normalmente.
- **Compressão:** o sha256 do sistema é medido **depois** de comprimir. Por isso a união roda no passo posterior à
  gravação e compressão (compara o arquivo já comprimido). Se a compressão não for determinística o arquivo
  simplesmente não une: perde economia, nunca correção. Medir a taxa real de união antes de prometer economia.
- **Referências:** o arquivo físico só é removido quando **a última** linha que o usa sair (pasta_documento ativa ou na
  lixeira, mais `cliente_documento`). Contagem **dentro da transação** com trava de linha em `arquivo_armazenado`
  (`SELECT ... FOR UPDATE`) para não correr contra um vínculo novo. Remoção física só após o COMMIT
  (`RemocaoAposTransacao`, regra da skill `armazenamento-de-arquivos`); nada de `->excluir(` fora dela.
- **Chave de leitura:** `ChavesDePasta::documento` hoje deriva a chave da entidade. Com arquivo compartilhado a chave
  vem de `arquivo_armazenado.chave_fisica`; `novoDocumento` e `documento` têm de continuar batendo
  (`FabricasDeArquivoNovoTest` cobre).
- **Regravação no mesmo lugar** (compressão tardia, edição de peça): hoje regrava a mesma chave. Com mais de um vínculo
  isso mudaria o documento de outra pasta. Regra: arquivo com mais de 1 referência **não é regravado no lugar**; a
  edição gera arquivo novo para aquele documento (copia ao gravar).
- **Cota (decidido pelo Samuel, 31/10):** o espaço conta **uma vez por pasta**, como hoje, mesmo com o arquivo guardado
  uma vez. O consumo continua somando `tamanho_bytes` por linha; nada muda em `ConsumoDeArmazenamentoRepository`. Por
  consequência, a frase "espaço economizado" do protótipo **não aparece para o cliente** (seria falsa). A economia é
  só de disco do sistema.
- **Lixeira e LGPD (decidido pelo Samuel, 31/10):** o documento sai **uma pasta por vez**; o arquivo só some de vez
  quando sai da **última** pasta que o usa. **Não existe botão "apagar de todas as pastas".** Excluir um documento tira
  só aquele vínculo. A lixeira (30 dias) segura a linha, logo o arquivo; restaurar nunca encontra arquivo purgado,
  porque a linha da lixeira conta como referência. Não há hoje fluxo "exclusão LGPD apaga na hora" na Pasta; se o dono
  pedir, vale a mesma regra (o vínculo some na hora, o arquivo só com a última referência).
- **Purga do escritório:** `PurgarEscritorioUseCase` apaga `arquivo_armazenado` do tenant (entrar em `ORDEM_DELECAO`,
  `AuditavelCoberturaTest` e `PurgaCoberturaSchemaTest` exigem) e remove cada chave física uma vez.
- **R2:** nenhuma mudança no código do R2 (frente exclusiva da outra sessão). Contrato: continua 1 chave física = 1
  arquivo; o evento de exclusão sai **uma única vez**, quando a última referência cai. A outra sessão precisa ser
  avisada antes do merge de C.
- **Drive:** `ReconciliadorDePasta` trata cópia como documento novo; confirmar que `driveFileId` por linha não quebra
  com arquivo compartilhado.
- Testes: união só com igualdade total; arquivos de mesmo hash e conteúdo diferente (simulado) não unem; excluir um
  vínculo mantém o arquivo; excluir o último remove após o COMMIT; corrida (dois vínculos novos e uma exclusão);
  purga do escritório; cota; tenant isolado (mesmo arquivo em dois escritórios nunca une).
- Revisão: `feature-review-agent` duas vezes (ALTO).

### D. Vincular documento a outra pasta (sem migration se C estiver pronto, ALTO)

- Depende de C. "Vínculo" = nova linha `pasta_documento` na pasta de destino apontando para o **mesmo**
  `arquivo_armazenado`. Não existe entidade nova de vínculo.
- Regras: mesmo escritório; permissão **editar** na pasta de destino e **ver** na de origem; respeita cadeado
  (pasta oculta/trancada não aparece como origem nem destino); escolha da seção na pasta de destino; documento da
  lixeira não pode ser vinculado; vínculo repetido na mesma pasta é recusado (aviso, não erro).
- Cada vínculo tem vida própria (nome, seção, lixeira, assinatura marcada). O Registro de ambas as pastas conta o
  vínculo. Quem vincula não ganha acesso à pasta de origem além do arquivo vinculado.
- Cota e lixeira como definido em C.

## Fora de escopo (não faz parte desta frente)

- Detecção de assinatura digital e compactação: já existem; o protótipo só as repete na tela.
- "Varredura a cada 10 minutos": era limpeza do localStorage. No servidor, a consistência é transacional; um comando
  de conferência (arquivo sem referência, referência sem arquivo) pode vir depois, não é requisito.
- Migrar o acervo antigo para `arquivo_armazenado` (comando separado, depois de C estável e com autorização).
- R2: nenhum código tocado.

## Perguntas de negócio para o Samuel (linguagem simples)

1. ~~Espaço~~ **Respondida (31/10): conta uma vez por pasta, como hoje.**
2. ~~Exclusão~~ **Respondida (31/10): uma pasta por vez; o arquivo só some da última; sem "apagar de todas".**
3. **Alertas**: pasta parada usa os prazos que o relatório já usa (30, 60 e 90 dias). Prescrição avisa a 90 dias
   (alta a 30 dias ou vencida). Esses números estão certos?
4. **Contrato, unidade e titular do extrato**: hoje a pasta não tem esses campos. Quem preenche é a pessoa, à mão, no
   "Cadastro da pasta", ou isso vem do extrato da cobrança?
5. **Cadastro com pendência**: réu sem CPF ou sem qualificação aparece como "pendente" e só avisa. Deve também
   **impedir** algo (por exemplo, gerar a petição), ou só avisar?

## Ordem e dependências

A (sem migration) → B (migration) → C (migration, depois de B mergeado) → D. Uma migration por vez entre PRs abertos.
Cada fatia: spec curta atualizada aqui, testes antes do resto, PR pequeno, `mudancas/`, "o que olhar na tela" para o Samuel.
Pergunta 4 trava B; as perguntas 1 e 2 estão respondidas; 5 trava só a parte "impedir" de B.
