---
tipo: novidade
---
## Changelog
- Contribua C4: integração bancária do Master com a Cora (Integração Direta, mTLS, token de 24 h; certificado e chave cifrados com `CONTRIBUA_CRYPTO_KEY`, a tela mostra só os nomes); PIX dinâmico por conta (QR Code Pix v2) no lugar do estático quando a integração está conectada, com volta ao estático sem CNPJ, abaixo de R$ 5 ou com falha do banco; webhook `/api/webhooks/cora/pix` só como aviso, com reconsulta mTLS que confirma o apoio uma vez; conciliação `app:cora:conciliar`; prêmio do apoiador na página Contribua; interface `BancoPix` para Inter, Banco do Brasil e API PIX do BACEN depois. Tabelas `contribua_integracao_bancaria` e `contribua_cobranca_pix`; colunas novas em `contribua_apoio`.

## Para o cliente
- Quando a BlueJus liga a integração com o banco, o PIX de apoio da sua conta passa a ser confirmado sozinho, e você recebe uma mensagem de agradecimento.
