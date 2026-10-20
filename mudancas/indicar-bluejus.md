---
tipo: novidade
---
## Changelog
- Indicar o BlueJus: item no menu do usuário (para todos os logados, com ou sem escritório) que abre a janela do desenho, com convite por e-mail (modelo fixo pelo Carteiro, lista `indicacao`, com descadastro), WhatsApp e cópia do link curto `/i/{codigo}`, e a lista "Suas indicações" com "Conta criada" ou "Enviada".
- Regras: cadastro completo para indicar (nome com duas palavras e CPF; o telefone passa a valer quando o Meu Perfil tiver o campo), pausa global, pausa por pessoa, limite de 20 por dia e 200 por mês em janela móvel, contagem travada por pessoa contra envio simultâneo.
- Alerta "Disparo alto de convites" no sino dos Masters quando a contagem de 24h de alguém cruza o alerta configurado.
- Domínio novo `App\Indicacao` (tabelas `indicacao`, `indicacao_codigo`, `indicacao_config`, `indicacao_pausa`, `indicacao_alerta`) e os UseCases do card "Indicações do BlueJus" do Painel do Master (a tela do card chega em outra entrega).
- Link curto `/i/{codigo}` público: guarda o código na sessão e leva ao cadastro.

## Para o cliente
- Agora você pode indicar o BlueJus para colegas e outros escritórios pelo menu do seu nome: por e-mail, pelo WhatsApp ou copiando o seu link de convite.
