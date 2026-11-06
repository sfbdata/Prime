---
tipo: interno
---
## Changelog
- `ExpedienteQueriesDaPaginaTest`: teto de consultas do /expediente baixado para o que a página faz depois do #679 (contagens EXPEDIENTES numa consulta). Primário de 35 para 30, colaborador de 31 para 26. Assim, se uma consulta voltar a repetir, o teste quebra.

## Para o cliente
- A tela Expedientes ganhou uma trava automática que impede que ela volte a ficar lenta em atualizações futuras: se alguma mudança fizer a tela buscar dados repetidos, o sistema avisa antes de a versão chegar ao cliente.
