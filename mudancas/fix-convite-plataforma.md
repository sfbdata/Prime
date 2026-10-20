---
tipo: correcao
---
## Changelog
- O convite de plataforma abria o formulário de convite de escritório: o tipo era gravado como "platform" e lido como "plataforma".
- "Meus convites" nunca listava convite de escritório: o tipo era gravado como "office" e filtrado como "escritorio". Os dois lados usam agora as mesmas constantes.
- O aceite de convite (plataforma e escritório) recusa senha com menos de 8 caracteres também no servidor; o de plataforma exige nome e recusa convite de outro tipo.

## Para o cliente
- A página "Meus convites" volta a mostrar os convites de escritório recebidos.
