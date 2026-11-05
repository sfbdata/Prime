---
tipo: correcao
---
## Changelog
- Controladoria (auditoria de segurança, item 1.21): o cartão que a rotina de segunda-feira grava no Registro da pasta não cita mais pasta com cadeado nos alertas "Duas pastas no mesmo contrato" e "Duas pastas na mesma unidade", mesmo quando o autor do cartão é o primário. `LancarAlertasDaControladoriaUseCase` passa `CadeadoDaPastaRepository::idsDasPastasComCadeado` para `AlertasDaPasta::montar` (parâmetro novo `$semCitar`). O painel da Controladoria segue calculando pelo leitor. Teste: `RotinaDaControladoriaTest::testCartaoNaoCitaPastaComCadeado`.

## Para o cliente
- O aviso semanal da Controladoria gravado no Registro da pasta não cita mais o número de pastas trancadas com cadeado. O painel da Controladoria continua mostrando a cada pessoa as pastas repetidas que ela pode ver.
