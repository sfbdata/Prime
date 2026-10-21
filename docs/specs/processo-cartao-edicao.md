# Cartão do processo: edição no cartão, selo "manual" e marcas (P5b)

Desenho: `docs/design/redesign-19-10-26/02 - EXPEDIENTES - VS 18.10.26.dc.html` (fora do git). Markup do cartão
N:1700-1820 (marcas N:1762-1769, linha de fonte N:1770-1776, painel N:1777-1793, edição N:1794-1818); lógica
`PJE_CAMPOS`, `pjeDados`, `pjeVals` N:2992-3054 e `procMenu` N:3056-3077. Risco BAIXO, com o trecho de sigilo revisado
de novo (toca o bloqueio da IA e do link externo).

## Decisões

Decisor imparcial (feature-review-agent, 19/10), confirmadas pelo coordenador:

- D1. Os valores manuais ficam em colunas próprias no `processo`, todas anuláveis: `classe_manual`, `assuntos_manual`
  (json, lista), `jurisdicao_manual`, `orgao_manual`, `distribuicao_manual` (date), `valor_causa_manual`
  (decimal 15,2), `segredo_manual`, `digital_manual`, `gratuita_manual`, `tutela_manual`, `prioridade_manual`
  (bool), mais `dados_manuais_em` e `dados_manuais_por_id`. Nulo = sem valor manual. O Datajud
  (`DatajudProcessoMapper`) nunca escreve nessas colunas.
- D2. Sem integração com o PJe (P5a, decisão do dono), o que o Datajud trouxe é o "valor base" da regra de
  precedência do desenho: classe, assuntos, órgão, distribuição e o segredo (`nivelSigilo`). O valor da causa tem como
  base o `Pasta.valorCausa` da pasta em que o cartão está (o cartão de hoje já o mostra); não há cópia em nenhum
  sentido. O selo "manual" só aparece quando há valor manual. Linha de fonte com os textos do desenho para quem não
  tem integração: "Preenchido manualmente em {dd/mm/aaaa, HH:MM} · integração com o PJe não configurada"
  (`bi-pencil-square`) ou "Integração com o PJe não configurada. Preencha manualmente." (`bi-cloud-slash`). Nada de
  "Dados do PJe" nem botão "Atualizar". A fonte é plugável: `IntegracaoComPjeInterface` (hoje, "não configurada").
- D3. Salvar segue o `salvar` do desenho: valor vazio ou igual ao valor base não vira manual (volta ao base); o
  diferente e preenchido vira manual. Nas flags, "Não informado" = sem valor manual.
- D4. Sigilo efetivo = `nivelSigilo > 0` OU `segredo_manual = true` (`Processo::emSegredoDeJustica()`), usado nos
  leitores que hoje olham `nivelSigilo > 0` (contexto da IA do Push e dos agentes, link externo do Push). "Não" manual
  não desliga o sigilo do tribunal: recusa com "O tribunal informou segredo de justiça neste processo. Mantenha
  "Sim" ou "Não informado"." (lado seguro; vai à lista do dono).
- D5. Quem edita: EDIT no processo (`RESOURCE_PROCESSO`, a guarda do `processo_edit`), processo e pasta do escritório,
  processo vinculado à pasta, pasta viva (lápide recusada pelo listener). Trilha: `dados_manuais_em/por` e uma linha
  em `audit_log` com antes e depois. Quando o processo está em mais de uma pasta, o aviso depois de salvar é "Dados do
  processo salvos. Valem para todas as pastas com este processo." (texto novo, vai à lista do dono); senão "Dados do
  processo salvos.".
- D6. A dica "Preencha apenas o que você confirmou nos autos. Campos vazios ficam como "Não informado"." fica literal.
- D7. "Prioridade?" e o chip "Prioridade" ficam literais; não se ligam ao `PrioridadePasta`.
- D8 (revisão, 19/10). Igual ao base é comparação EXATA, como o `JSON.stringify` do desenho (mudar só a caixa vira
  manual), com a mesma limpeza de espaços dos dois lados (espaço duplo do Datajud não vira manual à toa). Salvar sem
  mudar nada não grava nem deixa rastro. `dados_manuais_em` existe só quando há valor manual (liga a linha "Preenchido
  manualmente em…"); limpar tudo volta ao estado sem edição, com a trilha no `audit_log`. O selo "manual" só aparece
  quando o valor exibido é o manual (o "Sim" do tribunal vence um "Não" manual antigo, sem selo). Na faixa de cima,
  Classe e Assunto mostram o manual quando há. O aviso conta só pastas fora da lápide.

## Tela

- Resumo (N:3040): "Órgão julgador · Valor da causa · Última distribuição" com o valor efetivo; vazio "Não informado".
- Marcas (N:3041-3042): chips só das flags efetivas "Sim", na ordem "Justiça gratuita" (`bi-heart-pulse`),
  "Tutela/liminar" (`bi-lightning-charge`), "Prioridade" (`bi-flag`), "Segredo de justiça" (`bi-lock`), "Juízo 100%
  digital" (`bi-pc-display`).
- Linha de "Ver todas as informações" com a linha de fonte ao lado (N:1770-1776).
- Painel "todas as informações" (N:1777-1793): os 11 campos do desenho sempre, com "Não informado", selo "manual"
  (dica "Preenchido manualmente"), assuntos em lista com marcador e flags "SIM"/"NÃO". Depois deles, os campos do
  Datajud que o desenho não tem e o cartão já mostrava (instância, tribunal, situação, baixa, sistema, formato, origem,
  partes, última atualização no tribunal), só quando há dado.
- ⋮ "Editar dados do processo" abre a edição no cartão (N:1794-1818): dica, campos com os rótulos e placeholders de
  `PJE_CAMPOS`, flags "Sim / Não / Não informado", "Cancelar" e "Salvar". Só com D5.
- Rota `POST /pasta/{id}/processos/{processoId}/dados` (`pasta_processo_dados_salvar`), CSRF
  `processo_dados_{processoId}`, XHR devolve `{sucesso, html, aviso}` (contrato do cartão) e o JS troca o cartão.
  Erros de validação: "Data inválida. Use dd/mm/aaaa.", "Valor da causa inválido. Use R$ 0,00.", "Texto longo
  demais." e o de D4.

## Para o dono

- D4 (recusar "Não" manual quando o tribunal informou sigilo) e o texto novo do aviso de D5.
- P5a (PJe/MNI e "Abrir no PJe") segue com o dono; este PR deixa a fonte plugável.
- "Ação" na linha Classe · Tribunal · Situação (N:1732) é item do desenho fora desta frente (PMC-006).
- O valor da causa manual fica no processo e se sobrepõe ao `Pasta.valorCausa` no cartão de todas as pastas com o
  processo; quem edita é quem tem EDIT no processo (mesmo sem EDIT na pasta). A média por cliente e a IA continuam
  lendo o `Pasta.valorCausa`.
- A IA continua recebendo classe, órgão e assuntos do Datajud, não os manuais (só o sigilo manual vale para ela).
  Levar os manuais ao prompt é frente própria (campo novo neutralizado, regra 5 do domínio Inteligencia).

## Testes mínimos

Unit: precedência (manual × base × nulo), salvar igual ao base não grava, datas e valores, segredo (união e
recusa), mapper do Datajud não toca as colunas manuais. Functional: rota (CSRF, sem EDIT no processo, outro
escritório, processo não vinculado, lápide), cartão com selo, marcas, linha de fonte e formulário, aviso com mais de
uma pasta, auditoria, link externo e IA bloqueados com "Sim" manual.
