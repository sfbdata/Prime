---
tipo: correcao
---
## Changelog
- Código pessoal BlueJus (`CodigoPessoal::garantir`): a primeira gravação passa a ser um UPDATE condicional (`codigo_pessoal IS NULL`) seguido da leitura do que ficou no banco, sem flush. Duas visitas ao mesmo tempo na primeira vez (Meu Perfil e Pastas recebidas, ou duas abas) não geram mais dois códigos, em que o segundo trocava o que a primeira tela já mostrara. Código repetido entre duas pessoas no mesmo instante cai no índice único e é sorteado de novo. Teste `CodigoPessoalGarantirTest`.

## Para o cliente
- O seu código BlueJus (Meu Perfil) agora é sempre o mesmo desde a primeira vez que aparece, mesmo que você abra o sistema em duas abas ao mesmo tempo.
