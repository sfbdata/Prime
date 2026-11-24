---
tipo: novidade
---
## Changelog
- Convênios, PR 5 da spec `docs/specs/convenios.md`: código de convite. O primário do escritório digita o código no Meu Perfil (bloco "Tenho um código de convênio", `POST /perfil/convenio/codigo`) e o escritório entra no convênio.
- `/cadastro?convenio=CODIGO` guarda o código e o aplica ao criar a conta (formulário, Google e Facebook); código que não vale não trava o cadastro.
- `AplicarCodigoDeConvenioUseCase`: confere chave geral, convênio ligado, validade, fim marcado, limite de usos e estado; toda recusa diz "Código inválido ou encerrado.". Trava do escritório e do convênio; Trilha no escritório.
- Limitador `convenio_codigo`: 5 tentativas por hora por escritório.
- Convênio de um estado: o vínculo fecha com `estado_mudou` se o escritório passar a ter outro estado.

## Para o cliente
- Quem recebeu um código de convênio de uma instituição (por exemplo, uma caixa de assistência) pode digitá-lo em Meu Perfil, no bloco "Tenho um código de convênio". Só o usuário principal do escritório vê esse bloco.
- O link de cadastro com o código da instituição já cria a conta dentro do convênio.
- Para evitar tentativas de adivinhar códigos, são 5 tentativas por hora por escritório.
