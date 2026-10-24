---
tipo: correcao
---
## Changelog
- Ponto: o lançamento manual e a edição manual de batida pelo admin (aba Ponto do colaborador) recusam data e hora acima de agora no relógio do servidor, com a mesma régua de `App\Ponto\Service\HorarioFuturoDaBatida`. O lançamento volta com o aviso; a edição reabre o formulário com o aviso no campo Hora. Nada é gravado. A hora exata de agora passa. Sem migration.

## Para o cliente
- O admin não consegue mais lançar nem editar uma batida para um horário que ainda não chegou; aparece o aviso "Não é possível registrar batida em horário futuro.".
