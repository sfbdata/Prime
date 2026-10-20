---
tipo: novidade
---
## Changelog
- Contribua, chave "Boleto" do desenho 08 (só com a Cora): botão "Gerar boleto" na seção Apoie, com o valor escolhido (mínimo de R$ 5,00), o CPF ou CNPJ do pagador (pré-preenchido com o do escritório), o e-mail de quem está logado e vencimento em 3 dias, sem multa e sem juros (`POST /v2/invoices/` com `BANK_SLIP` e `PIX`). Mostra a linha digitável com o botão de copiar, o link do PDF, o vencimento e o Pix copia e cola do boleto. Um boleto aberto por escritório, valor e dia (trava por escritório e chave de idempotência derivada do pedido); falha da Cora não grava nada e mostra uma mensagem honesta. O boleto é uma cobrança da mesma tabela das cobranças PIX por conta (colunas `forma`, `linha_digitavel`, `url_do_boleto`) e é confirmado pelo mesmo aviso, reconsulta e conciliação. Com Inter, BB ou Outro, a chave Boleto da Integração bancária fica desabilitada, com a nota "Disponível só com a Cora".

## Para o cliente
- Quem preferir pode apoiar o BlueJus por boleto bancário, gerado na hora na página Contribua, e o apoio é confirmado sozinho quando o boleto é pago.
