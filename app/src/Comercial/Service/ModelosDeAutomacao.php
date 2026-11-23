<?php

declare(strict_types=1);

namespace App\Comercial\Service;

use App\Comercial\Campanha\ComposicaoDaCampanha;
use App\Comercial\DTO\MensagemDaCampanha;
use App\Comercial\Enum\Automacao;
use App\Comercial\Exception\CampanhaRecusada;
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
        'espaco_100' => ['Espaço cheio no BlueJus: veja as opções de espaço extra', 'ESPAÇO ESGOTADO', '{nome}, o espaço da sua conta acabou'],
        'opcoes_liberadas' => ['Liberamos as opções de espaço extra para o seu escritório no BlueJus', 'ESPAÇO EXTRA', '{nome}, as opções de espaço extra foram liberadas'],
        'pedido_respondido' => ['Resposta ao seu pedido de espaço no BlueJus', 'PEDIDO DE ESPAÇO', '{nome}, veja a resposta ao seu pedido'],
        'pedido_espaco' => ['Recebemos seu pedido de mais espaço no BlueJus', 'PEDIDO RECEBIDO', 'Obrigado, {nome}! Já estamos cuidando disso'],
    ];

    /**
     * O padrão da Central que é a cópia de cada modelo de automação (M5.8): editado pelo Master, a automação passa a
     * enviar a versão dele. Fora daqui (o lembrete de confirmação, que leva o link único de cada pessoa, e os dois do
     * espaço extra, sem cópia na Central), o texto é sempre o do sistema.
     */
    public const FIXO_DA_CENTRAL = [
        'boas_vindas' => 'boasvindas',
        'primeiros_passos' => 'primeiros',
        'espaco_90' => 'espaco90',
        'espaco_100' => 'espaco100',
        'pedido_espaco' => 'pedido',
    ];

    public function __construct(
        private readonly Environment $twig,
        private readonly UrlGeneratorInterface $urls,
        private readonly ModelosEfetivos $efetivos,
        private readonly ComposicaoDaCampanha $composicao,
    ) {
    }

    /**
     * @param array<string, string> $variaveis além de `urlDoApp` e `urlDoEspaco`; o `e90` usa `limite`
     */
    public function montar(Automacao $automacao, ?string $nomeCompleto, array $variaveis = []): ModeloMontado
    {
        $chave = self::chaveDoModelo($automacao);
        $primeiroNome = self::primeiroNome($nomeCompleto);
        $editado = $this->editado($chave, $primeiroNome, $variaveis['limite'] ?? '');
        if ($editado !== null) {
            return $editado;
        }
        [$assunto, $selo, $titulo] = self::MODELOS[$chave];
        $corpo = $this->twig->render('email/comercial/automacao/' . $chave . '.html.twig', $variaveis + [
            'urlDoApp' => $this->urls->generate('app_login', [], UrlGeneratorInterface::ABSOLUTE_URL),
            'urlDoEspaco' => $this->urls->generate('contribua_index', [], UrlGeneratorInterface::ABSOLUTE_URL) . '#espaco',
        ]);
        $titulo = str_replace(['{nome}', '{limite}'], [$primeiroNome, $variaveis['limite'] ?? ''], $titulo);

        return new ModeloMontado($assunto, $selo, $titulo, $corpo);
    }

    /**
     * A versão do Master do padrão correspondente, ou nulo (sem cópia na Central, sem versão editada, ou versão que não
     * compõe: aí vale o texto do sistema, e a automação nunca deixa de sair). `{nome}` no corpo o montador troca.
     */
    private function editado(string $chave, string $primeiroNome, string $limite): ?ModeloMontado
    {
        $fixo = self::FIXO_DA_CENTRAL[$chave] ?? null;
        $versao = $fixo === null ? null : $this->efetivos->editadoDoFixo($fixo);
        if ($versao === null) {
            return null;
        }
        try {
            $m = MensagemDaCampanha::deArray($versao);
            [$corpo, $layout] = $this->composicao->corpoELayout($m);
        } catch (CampanhaRecusada) {
            return null;
        }
        if (trim($m->assunto) === '' || trim(strip_tags($corpo)) === '') {
            return null;
        }

        // `{limite}` (o percentual do aviso de 90%) vale no assunto, no título e no texto; `{nome}` no corpo o montador troca.
        $limitar = static fn (string $texto): string => str_replace('{limite}', $limite, $texto);

        return new ModeloMontado($limitar($m->assunto), $m->selo, $limitar(str_replace('{nome}', $primeiroNome, $m->titulo)), $limitar($corpo), $layout);
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
            Automacao::Lib => 'opcoes_liberadas',
            Automacao::Resp => 'pedido_respondido',
        };
    }
}
