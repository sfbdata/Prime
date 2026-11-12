# Voto: resultados com todos os eleitos

Continuação autorizada do handoff de 12/11 e da spec voto-pautas-reaproveitamento-1111.
Escopo do PR 4, após o merge do #732. Sem migration ou mudança de apuração.

## Plano e fluxo

1. Testar resultados, decisão no painel, ata e cédula para duas vagas e empate no corte.
2. Usar a lista `vencedores` já apurada, preservando os textos de vaga única.
3. Transportar critério/base da pauta para a ata, inclusive enquanto aberta.
4. Totalizar escolhas e seus pesos na tabela de votos; presença continua uma vez por unidade.
5. Revisar contra esta spec, validar Voto e qualidade, abrir PR. Smoke visual do Samuel.

Gestor autorizado consulta resultados/ata do próprio escritório; eleitor abre sua cédula pelo link.
Os UseCases ObterAtaDaAssembleia, ObterPainelDoVoto e MontarCedula já controlam as leituras.
A leitura apresenta o resultado somente após o encerramento. Empate no corte e falta de quórum
não proclamam eleitos. A entrega altera apresentação, sem escrita de votos ou vínculo com escolhas.

## Inventário e aceitação

Referência: docs/design/voto/export-2026-11-11/consulta/voto.dc.html (cópia na raiz).
Inventário mecânico da sessão: /tmp/inventario-voto-resultados.md.

| Região | Desenho | Destino | Conferência |
| --- | --- | --- | --- |
| Resumo dos resultados | `resTx` L365: Eleitos: número nome, número nome | faixa do gestor e resumo da cédula | ✓ conferido no código |
| Decisão | L182: verde #1e7b45 com eleitos; vermelho #a3232b sem decisão | decisão do gestor e da cédula | ✓ conferido no código |
| Ata, resumo e resultado | L356/365: lista de eleitos ou situação | dois trechos da ata | ✓ conferido no código |
| Critérios da ata | L356: critério sobre base, quórum N% | tabela de critérios, aberta e encerrada | ✓ conferido no código |
| Total da apuração | L356: soma de escolhas + abstenções, respectivos pesos | linha Total da ata | ✓ conferido no código |
| Participação | L356: comparecimento e cédulas separados dos votos | KPIs, eleitorado e encerramento | ✓ conferido no código |
| Empate na última vaga | núcleo: situação sem eleitos | todas as apresentações | ✓ conferido no código |

Preservar classes, cores, barras, responsivo e arranjo existentes, sem criar componentes novos.
Vaga única mantém seus textos já publicados. Etapas seguintes do handoff ficam fora deste PR.

## Prova e limites

- Cinco cenários novos reproduziram as lacunas antes da implementação. Os de empate já passavam.
- Suíte do Voto: 325 testes, 1.647 asserções também após a correção final dos caracteres especiais.
- PHPStan sem erros; 25 templates Twig e o YAML de traduções com sintaxe válida.
- Conferência independente read-only: os sete itens do inventário foram atendidos.
- Revisão encontrou escape duplicado no resumo da ata; composição foi corrigida para escapar uma vez.
  O cenário usa nome com `&` e `<Cia>` para verificar a exibição literal segura.
- A conferência acima é de código. Samuel confere visualmente as listas de eleitos no painel,
  na cédula e nos dois pontos da ata; empate no corte deve permanecer sem decisão verde.

A suíte completa local foi interrompida após mais de 5.500 testes sem falhas reportadas; a validação completa segue pelo CI paralelo do PR.
