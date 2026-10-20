# Expediente contra o desenho 03 VS 18.10.26: estado das frentes

Risco BAIXO (sem spec de regra). Este arquivo só guarda o estado, para retomar depois de uma compactação de contexto.

## Estado da frente (atualizado em 19/10/2026; reler antes de continuar se a sessão for compactada)

- **Sessão:** 5d. Desenho: `03 - EXPEDIENTE - VS 18.10.26.dc.html` (projeto claude.ai/design 4f6a5f61), lido por
  DesignSync `get_file` (só leitura).
- **PRs e pilha:**
  - #51 `expediente-revisao-comandos`: comandos, botão direito, colunas, Réu, F2. Base master. Pronto para merge.
  - #80 `expediente-aud29`: colunas de 901 a 1280px. Empilhado no #51.
  - #85 `expediente-acertos`: acertos finais da conferência de 19/10 (dicas, menu de responsável, clique com menu
    aberto, Nova Pasta fica na lista, Limpar sem zerar a Situação). Empilhado no #51.
  - Ordem: #51 primeiro; #80 e #85 não se tocam e entram em qualquer ordem depois. Depois do #51, trazer o master para
    as duas branches.
- **Decisões do dono:** 19/10, AUD-29 segue o desenho 03 (saem Ação, Responsável e Metas de 901 a 1280px; o Réu fica),
  substituindo a de 15/10. 19/10 (coordenador): "tudo do desenho entra; onde o desenho mostra diferente, o desenho
  manda" (por isso a Nova Pasta fica na lista e o Limpar não zera a Situação, contra o plano E8 S23).
- **Contratos com outras sessões:** o X global é do #71 (5d), com o include no #69 (7d); o menu de responsável tem
  `role="dialog"` e X próprio, que o #71 reconhece. "Indicar o BlueJus" é da 7d.
- **Arquivo quente tocado:** `base.html.twig` (#85, 3 `data-tip`); também mexem nele os PRs #56, #58, #68 e #74.
- **Migrations:** nenhuma.
- **Pendências do desenho 03 fora do Expediente, da 5d:** seletor "Ausente após" (fase 3 da presença, com migration) e
  "Instalar aplicativo" sem o prompt do navegador (menu do usuário, avisar a 7d do arquivo).
- **Próximo passo:** aguardar os merges; trazer o master depois do #51.
