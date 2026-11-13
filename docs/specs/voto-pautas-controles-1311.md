# PR 5: controles das pautas

O gestor configura uma pauta em rascunho para que a cabine e a apuração usem suas regras.
Pré-condições: acesso da equipe, assembleia do escritório, CSRF e rascunho.
Fluxo: carregar configuração, editar ou aplicar regra pronta, numerar opcionalmente, salvar e recarregar.
Erros: limites inválidos são recusados; falha de rede preserva edição; mudanças durante fetch continuam pendentes.
Pós-condição: configuração persistida. Publicação mantém o congelamento existente e nenhuma regra de apuração muda.
UseCases AdicionarPautaUseCase e EditarPautaUseCase já implementam esse contrato, com testes unitários existentes.

Fonte: consulta/voto.dc.html L216 e L416, export de 11/11. Escopo autorizado pelo handoff canônico de 12/11.
Antes: título e Candidatura na linha; número com um dígito; regras disponíveis apenas no servidor.
Depois: título, regra pronta, vagas, dígitos, critério, base, dois quóruns, Candidatura e Numerar na mesma linha flex.
Preservam-se funções existentes. Sem migration, sem mudança em permissões, presença ou sigilo.

| Item | Valor do desenho | Implementação / conferência |
| --- | --- | --- |
| Linha | flex, gap 8px, align-items flex-end, wrap | _pauta_form e voto.css |
| Título | flex 1 1 220px | preservado |
| Regra pronta | flex 1 1 230px; Personalizada e quatro presets | select e JS |
| Vagas | 80px; 1 a 9; vazio 1 | input e servidor |
| Dígitos | 96px; 1 a 5; não renumera | select e maxlength |
| Critério | flex 1 1 170px; mais, absoluta, dois, unanime | select |
| Calculado sobre | flex 1 1 150px; validos, presentes, aptos | select |
| Quórum (%) | 100px; 0 a 100; vazio 0 | input e servidor |
| Quórum 2ª conv. | 112px; até 100; vazio null | input e servidor |
| Numerar | vB, bi-123, título Numerar as opções em sequência | sequência atual 1..N com zeros |
| Candidatura | maior número + 1 com zeros; vazio se não couber | JS |
| Presets | simples=[mais,validos,50,0]; absoluta/dois/unanime=[critério,aptos,0,null] | JS; reconhecimento ignora segunda |
| Personalizada | não muda valores | JS |
| Selects e autosave | origem por defaultSelected; restauração e snapshot durante fetch | retrato e teste Node |

Defaults legados: dígitos e vagas 1; critério mais; base validos; primeira 50 e segunda 0.
Teste funcional confere filhos diretos, envio comum, gravação e recarga. Teste Node sem navegador cobre interações.
Smoke visual fica com Samuel: linha em tela estreita, presets, numeração e salvar ao sair.

## Conferência final do inventário

| Item | Resultado contra código |
| --- | --- |
| Linha flex e título | ✓ gap 8px, wrap, alinhamento inferior, flex 1 1 220px |
| Regra pronta | ✓ flex 1 1 230px; cinco opções literais |
| Vagas e dígitos | ✓ 80px e 96px; limites, defaults e select 1 a 5 |
| Critério e base | ✓ flex 1 1 170px e 1 1 150px; opções do desenho |
| Dois quóruns | ✓ 100px e 112px; vazio 0/null; defaults legados 50/0 |
| Numerar | ✓ vB, bi-123, título literal e sequência com zeros |
| Candidatura | ✓ maior número + 1; vazio quando não couber |
| Presets e Personalizada | ✓ valores conferidos; identificação ignora segundo quórum |
| Persistência e recarga | ✓ teste funcional por formulário e testes fetch existentes |
| Autosave dos selects | ✓ defaultSelected, restauração, beforeunload, saída e snapshot durante fetch |
| Restauração de dígitos | ✓ maxlength atualizado ao iniciar e no pageshow sem renumerar |

Revisão read-only aprovada após corrigir sincronização do maxlength restaurado.
Conferência visual no navegador permanece com Samuel.

Comandos: `scripts/frente-testar.sh voto-pautas-controles tests/Voto` (327 testes, 1.674 asserções),
`node tests/js/voto-pautas.test.cjs` (7 testes), `node --check app/public/js/voto-assembleia.js`,
`scripts/qa.sh` (PHPStan sem erros), lint Twig (25 arquivos) e YAML (1 arquivo).
