---
tipo: novidade
---
## Changelog
- Contribua C4b/C4c: Banco Inter, Banco do Brasil e "Outro (API PIX padrão BACEN)" habilitados na integração bancária do Master, sobre uma base comum da API Pix do BACEN (`ClientePixBacen`: OAuth2 client_credentials, mTLS em toda chamada, token guardado cifrado, 401 renova uma vez; cobrança `PUT /cob/{txid}` e consulta `GET /cob/{txid}`); txid gerado pelo adaptador, alfanumérico e determinístico pela tentativa; campos extras só para o banco que os pede (Client Secret e app key cifrados e nunca devolvidos à tela, chave PIX, URLs do PSP); certificado `.pfx`/`.p12` com senha (a senha não é gravada); webhook `/api/webhooks/{banco}/pix` que registra só txid nosso e reconsulta no banco; conciliação `app:contribua:conciliar` para o banco ativo (`app:cora:conciliar` continua como apelido). Colunas novas em `contribua_integracao_bancaria`.

## Para o cliente
- O apoio por PIX com confirmação automática passa a funcionar também com Banco Inter, Banco do Brasil e outros bancos que seguem o padrão PIX do Banco Central.
