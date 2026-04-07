<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\Validator\Constraints\NotBlank;

class JustificativaPontoType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('datas', HiddenType::class, [
                'mapped'      => false,
                'constraints' => [
                    new NotBlank(message: 'Selecione ao menos uma data.'),
                ],
            ])
            ->add('descricao', TextareaType::class, [
                'label'       => 'Motivo / Descrição / Esquecimento',
                'mapped'      => false,
                'attr'        => ['rows' => 3, 'placeholder' => 'Descreva o motivo da ausência ou detalhes do esquecimento'],
                'constraints' => [
                    new NotBlank(message: 'Informe o motivo da ausência.'),
                ],
            ])
            ->add('anexo', FileType::class, [
                'label'       => 'Atestado / Comprovante',
                'mapped'      => false,
                'required'    => false,
                'constraints' => [
                    new File([
                        'maxSize'          => '10M',
                        'mimeTypes'        => ['application/pdf', 'image/jpeg', 'image/png'],
                        'mimeTypesMessage' => 'Somente PDF, JPEG ou PNG são aceitos.',
                        'maxSizeMessage'   => 'O arquivo não pode exceder 10 MB.',
                    ]),
                ],
            ])
;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'     => null,
            'csrf_token_id'  => 'justificativa_ponto',
        ]);
    }
}
