<?php

declare(strict_types=1);

namespace App\Ponto\UseCase;

use App\Entity\Tenant\Sede;
use App\Entity\Tenant\Tenant;
use App\Ponto\DTO\SedeDoPontoInput;
use App\Ponto\DTO\SedeDoPontoOutput;
use App\Ponto\Exception\ConfiguracaoDoPontoRecusadaException;
use App\Ponto\Validacao\RestricoesDaSede;
use App\Repository\SedeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Cria ou grava uma sede pela tabela "Sedes e raio de registro" da aba Configurações (spec
 * `docs/specs/ponto-desenho-0811.md`, E6: P68, P70 a e D13). A linha inteira vai a cada mudança de campo.
 *
 * - Coordenada com vírgula vira ponto (o desenho aceita "-9,66"); a página antiga continua recusando a vírgula.
 * - Raio vazio vira o da sede nova (150 m, `Sede::RAIO_PADRAO`); a sede que já existe só muda o raio quando a
 *   pessoa troca o número.
 * - Latitude, longitude e raio passam pela mesma regra da página antiga (`RestricoesDaSede`); nada é gravado se um
 *   campo falhar, e cada erro volta com o nome do campo para aparecer na linha.
 * - A sede é buscada pelo id E pelo escritório da sessão: a de outro escritório é "não encontrada" (404).
 */
final class SalvarSedeDoPontoUseCase
{
    public const NOME_MAXIMO = 255;

    public function __construct(
        private readonly SedeRepository $sedes,
        private readonly EntityManagerInterface $em,
        private readonly ValidatorInterface $validador,
    ) {
    }

    /** @throws ConfiguracaoDoPontoRecusadaException */
    public function executar(SedeDoPontoInput $dados, Tenant $escritorio, ?int $id = null): SedeDoPontoOutput
    {
        $sede = $id === null ? (new Sede())->setTenant($escritorio) : $this->daSessao($id, $escritorio);
        $latitude = self::numero($dados->latitude);
        $longitude = self::numero($dados->longitude);
        $raio = self::numero($dados->raio) ?? (string) Sede::RAIO_PADRAO;

        $erros = $this->erros($dados->nome, $latitude, $longitude, $raio);
        if ($erros !== []) {
            throw ConfiguracaoDoPontoRecusadaException::invalida($erros);
        }

        $sede->setNome($dados->nome)->setLatitude($latitude)->setLongitude($longitude)->setRaioPermitido((int) $raio);
        if ($id === null) {
            $this->em->persist($sede);
        }
        $this->em->flush();

        return SedeDoPontoOutput::daSede($sede);
    }

    private function daSessao(int $id, Tenant $escritorio): Sede
    {
        return $this->sedes->findOneBy(['id' => $id, 'tenant' => $escritorio])
            ?? throw ConfiguracaoDoPontoRecusadaException::naoEncontrada('Sede não encontrada.');
    }

    /** @return array<string, string> campo => primeira mensagem */
    private function erros(string $nome, ?string $latitude, ?string $longitude, string $raio): array
    {
        $regras = [
            'nome'      => [$nome, [new NotBlank(message: 'Informe o nome da sede.'), new Length(max: self::NOME_MAXIMO, maxMessage: 'O nome da sede pode ter até 255 caracteres.')]],
            'latitude'  => [$latitude, RestricoesDaSede::latitude()],
            'longitude' => [$longitude, RestricoesDaSede::longitude()],
            'raio'      => [is_numeric($raio) ? (float) $raio : null, RestricoesDaSede::raio()],
        ];
        $erros = is_numeric($raio) ? [] : ['raio' => RestricoesDaSede::MENSAGEM_RAIO_NAO_NUMERO];
        foreach ($regras as $campo => [$valor, $restricoes]) {
            $violacoes = $this->validador->validate($valor, $restricoes);
            if (!isset($erros[$campo]) && \count($violacoes) > 0) {
                $erros[$campo] = (string) $violacoes->get(0)->getMessage();
            }
        }

        return $erros;
    }

    /** Texto do campo, com a vírgula decimal trocada por ponto; vazio vira null. */
    private static function numero(string $texto): ?string
    {
        $texto = str_replace(',', '.', trim($texto));

        return $texto === '' ? null : $texto;
    }
}
