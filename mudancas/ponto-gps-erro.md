---
tipo: correcao
---
## Changelog
- Ponto (`templates/ponto/index.html.twig`): o aviso de GPS distingue o erro do navegador. Erro 2 (posição indisponível) e 3 (demora) dizem "Não foi possível descobrir sua localização agora" / "demorou demais", com botão "Tentar de novo". Erro 1 consulta `navigator.permissions`: em "prompt", botão "Permitir localização" (abre a janela do navegador); em "denied", passo a passo para liberar no cadeado (com variação para celular); em "granted", o aparelho está bloqueando e o aviso manda ligar a localização do sistema. Quando a permissão vira "granted" com a página aberta, a busca recomeça sozinha. A validação de raio do servidor não mudou.

## Para o cliente
- Na tela do ponto, quando a localização falha, o aviso agora diz o motivo certo. Se o navegador não conseguiu achar a posição ou demorou, aparece um botão "Tentar de novo". Se a localização ainda não foi permitida, aparece o botão "Permitir localização". Se ela foi bloqueada, a tela mostra o passo a passo para liberar, no computador e no celular, e volta a buscar sozinha assim que for liberada.
