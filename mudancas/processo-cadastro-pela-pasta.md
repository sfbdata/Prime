---
tipo: alteracao
---
## Changelog
- Pasta, aba Processo: o cadastro de processo novo pela janela "Vincular processo" passa a ser validado no servidor (`CadastrarProcessoPelaPastaUseCase`): número completo no padrão CNJ, órgão julgador, classe e assunto obrigatórios ("Preencha: ..."), situação e instância só nas opções da janela, datas válidas e limites das colunas. A recusa responde 422 com a mensagem e não grava nada.
- Número que já é de um processo do escritório: vincula o existente, não sobrescreve nada e avisa "Este processo já estava cadastrado no escritório. Os dados digitados não foram aplicados.".
- "Buscar no CNJ": o resultado fica guardado no servidor por 30 minutos (por escritório, pessoa, sessão e número) e o cadastro aplica o que a janela não leva (assuntos, sigilo, formato, sistema, códigos, id e o bruto do Datajud). O que a pessoa editou na janela vale mais; nada disso é aceito vindo do navegador.
- "Buscar no CNJ" dentro da pasta vale para quem pode vincular processo nela (rota `POST /pasta/{id}/processo/buscar-cnj`), mesmo sem o módulo de processos. Fora da pasta continua exigindo o módulo.
- "Encaminhar via Chat I.A" no menu (⋮) do cartão do processo.

## Para o cliente
- Ao vincular um processo novo pela pasta, os dados do CNJ chegam completos (assuntos, sigilo, sistema) e o sistema avisa quando falta algo ou quando o processo já estava cadastrado.
- Quem edita a pasta já consegue usar o "Buscar no CNJ" na janela de vincular processo.
- O processo pode ser encaminhado a um colega pelo Chat I.A direto do cartão.
