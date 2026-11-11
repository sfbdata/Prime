---
tipo: novidade
---

## Para o cliente

- Assembleias publicadas agora têm um link público próprio e QR Code para divulgar no edital, com imagem PNG, versão SVG e cartaz para impressão.
- O participante informa nome, unidade e bloco, recebe um código de seis números no e-mail cadastrado e confirma o acesso à votação. O código vale por dez minutos, tem limite de tentativas e só funciona uma vez no navegador que o solicitou.
- O terminal presencial permite encerrar um atendimento e receber o próximo participante. Os links individuais já enviados continuam funcionando.

## Implantação

- Migration Version20261111132746: código público único nas assembleias existentes e novas; campos de controle do código por e-mail nas unidades. Não altera votos ou resultados já registrados.
- O QR Code usa o endereço público configurado em DEFAULT_URI. O Voto continua dependendo da ativação global e da liberação do escritório pelo Master.
