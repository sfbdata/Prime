---
tipo: correcao
---
## Changelog
- Rotina de segunda da Controladoria: os alertas do Cadastro (contrato ou unidade repetidos, titular diferente, prescrição próxima, cadastro incompleto) ganham tipo próprio. Antes saíam com o Tipo vazio no resumo do `--simular` e dividiam a mesma marca, então um alerta já lançado escondia os outros. A chave do `AlertaDaPasta` passa a ser obrigatória.

## Para o cliente
- No Registro da pasta, a Controladoria agora avisa cada problema do cadastro (contrato repetido, unidade repetida, titular diferente, prescrição chegando, cadastro incompleto) separadamente. Antes, depois do primeiro aviso, os outros podiam deixar de aparecer.
