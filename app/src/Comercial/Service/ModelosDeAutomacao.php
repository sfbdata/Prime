<?php

declare(strict_types=1);

namespace App\Comercial\Service;

use App\Comercial\Enum\Automacao;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

/**
 * Os modelos fixos das automações do desenho 05 (dc L.505-515): assunto, selo, título e o miolo. `{nome}` vira o
 * primeiro nome (dc L.484-487: "colega" sem nome). Nos de espaço (fatia 1b), `{limite}` vira o percentual escolhido no
 * `e90` (o dc mostra o padrão, 90%) e o botão leva à seção de espaço do Contribua.
 */
final class ModelosDeAutomacao
{
    private const MODELOS = [
        'boas_vindas' => ['Bem-vindo ao BlueJus, seu escritório digital', 'BEM-VINDO(A)', 'Seja bem-vindo(a), {nome}!'],
        'primeiros_passos' => ['Uma semana de BlueJus: dicas para render mais', 'DICAS', '{nome}, veja o que mais o BlueJus faz por você'],
        'lembrete_confirmacao' => ['Confirme seu e-mail para ativar o BlueJus', 'CONFIRMAÇÃO DE E-MAIL', '{nome}, falta só confirmar o seu e-mail'],
        'espaco_90' => ['Seu espaço no BlueJus está quase cheio', 'AVISO DE ESPAÇO', '{nome}, sua conta chegou a {limite}% do espaço'],
        'espaco_100' => ['Espaço esgotado: novos envios pausados no BlueJus', 'ESPAÇO ESGOTADO', '{nome}, o espaço da sua conta acabou'],
        'pedido_espaco' => ['Recebemos seu pedido de mais espaço', 'PEDIDO RECEBIDO', 'Obrigado, {nome}! Já estamos cuidando disso'],
    ];

    public function __construct(
        private readonly Environment $twig,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * @param array<string, string> $variaveis além de `urlDoApp` e `urlDoEspaco`; o `e90` usa `limite`
     */
    public function montar(Automacao $automacao, ?string $nomeCompleto, array $variaveis = []): ModeloMontado
    {
        $chave = self::chaveDoModelo($automacao);
        [$assunto, $selo, $titulo] = self::MODELOS[$chave];
        $primeiroNome = self::primeiroNome($nomeCompleto);
        $corpo = $this->twig->render('email/comercial/automacao/' . $chave . '.html.twig', $variaveis + [
            'urlDoApp' => $this->urls->generate('app_login', [], UrlGeneratorInterface::ABSOLUTE_URL),
            'urlDoEspaco' => $this->urls->generate('contribua_index', [], UrlGeneratorInterface::ABSOLUTE_URL) . '#espaco',
        ]);
        $titulo = str_replace(['{nome}', '{limite}'], [$primeiroNome, $variaveis['limite'] ?? ''], $titulo);

        return new ModeloMontado($assunto, $selo, $titulo, $corpo);
    }

    public static function primeiroNome(?string $nomeCompleto): string
    {
        $partes = preg_split('/\s+/', trim((string) $nomeCompleto)) ?: [];
        $primeiro = $partes[0] ?? '';

        return $primeiro === '' ? 'colega' : mb_convert_case($primeiro, MB_CASE_TITLE);
    }

    private static function chaveDoModelo(Automacao $automacao): string
    {
        return match ($automacao) {
            Automacao::Val, Automacao::Conv => 'boas_vindas',
            Automacao::Dica => 'primeiros_passos',
            Automacao::Cad, Automacao::Lemb => 'lembrete_confirmacao',
            Automacao::E90 => 'espaco_90',
            Automacao::E100 => 'espaco_100',
            Automacao::Ped => 'pedido_espaco',
        };
    }
}
