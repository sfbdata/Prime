# Aba Processo: acabamento do desenho 02 (P5d)

Desenho: `docs/design/redesign-19-10-26/02 - EXPEDIENTES - VS 18.10.26.dc.html` (fora do git). Inventário ✓/✗ contra o
master e a pilha (#118, #91, #75) aprovado pelo coordenador em 20/10, itens 1 a 4. Sem migration. Empilhado sobre
o #118 (usa as colunas `*_manual`); entra depois de #118, #91 e #75 (mesmo `_processos_vinculados.html.twig`).
Risco BAIXO.

## Itens

1. Faixa do cartão "Ação · Classe · Tribunal · Situação" (N:1732-1750). Ação: a da pasta (`Pasta.nomeAcao`) no
   processo principal; nos demais, ou sem ação, o assunto do processo (manual, se houver). Vazio: "Não informada".
2. Janela de vincular (N:289-354, `pvVals` N:3135-3151):
   - "Vincular processo" (minúsculas), rótulos em minúscula, placeholder "Ex.: …";
   - Valor da causa (máscara R$), Jurisdição e as 5 flags "Não informado / Sim / Não", gravados como dados MANUAIS
     do processo (P5b) no cadastro novo (`procSalvarVinc` N:4545-4548), pelo `VincularProcessoUseCase`;
   - Situação: Em andamento, Suspenso, Arquivado, Baixado, Transitado em julgado (códigos novos com rótulo em
     `SituacaoProcessoRotulo`); Instância: G1, G2, Turma Recursal (TR), Tribunal Superior (ST, código mantido);
     tipos de parte: Parte, Advogado, Terceiro interessado, Ministério Público, Perito;
   - "Dados localizados · N movimentação(ões)", "Processo não localizado no CNJ/diário. Preencha os dados
     manualmente." (404), "Informe o número completo no formato NNNNNNN-DD.AAAA.J.TT.OOOO.", rodapé da aba
     Movimentações e o vazio da busca "Nenhum processo encontrado. Use "Cadastrar novo (CNJ)".";
   - o que vem do Datajud passa a ser escapado antes de entrar no HTML das linhas de partes e movimentações (o JS
     interpolava sem escape).
3. Registro do vincular e do desvincular do processo (`autoReg('proc', …)` do desenho): "processo N vinculado à
   pasta (ação · tribunal)." e "processo N desvinculado da pasta.", pelo `RegistroDoSistemaNaPasta` (chave `proc`,
   a mesma do #91). O cartão (PastaMensagem) é `Auditavel`: a trilha sai pelo AuditLogSubscriber, sem linha manual e
   sem dado de cliente.
4. Detalhes: o item do ⋮ "Ver todas as informações" vira "Mostrar menos" junto com o botão; title do "Peticionar na
   pasta" do desenho. O ⋮ do cabeçalho já abria com o botão direito.

## Decisões

- (a) "Copiar/Imprimir resumo": segue o desenho (as linhas Ação, Classe, Tribunal, Situação, Órgão julgador e Valor da
  causa sempre, com "Não informado"; sem "Distribuição"). A regra antiga ("só dado real") era escolha técnica, sem
  decisão do dono (critério do coordenador, 20/10). Uma fonte só para a tela e o PDF: `ResumoDoProcesso`.
- (b) Notas técnicas, exceção do primário e "Editar · N min": NÃO mudam. É pendência do dono (D-MASTER, "Depende
  genuinamente do Samuel" em `docs/specs/trilha-b-designer.md`); vai ao pacote "Desenho 02 x decisões antigas".
- Fora: PJe (estados de fonte, "Atualizar", "Abrir no PJe", "Buscar no CNJ" com PJe e diários) e OCR, com o dono;
  "Encaminhar via Chat I.A", PR próprio sobre o #126 (5d).
- Integração com o #91 no merge: o `DesvincularProcessoUseCase` dele registra só o apensado; aqui, todo
  desvincular. Juntar: apensado com o texto dele, os demais com o texto daqui.
