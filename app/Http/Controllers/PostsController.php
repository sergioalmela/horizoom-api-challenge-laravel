<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Controlador que maneja los post provinientes de la API
 * @author Sergio
 * @link https://jsonplaceholder.typicode.com/posts
 */
class PostsController extends Controller
{
    //Una vez recuperados los datos, buscamos la palabra introducida y exportamos a CSV
    public function index()
    {
        //Validamos el formulario, siendo obligatorio introducir la palabra
        $data = request()->validate([
            'word' => ['required', 'string']
        ]);

        $word = $data['word'];

        //Recuperamos los post desde la API
        $posts = $this->fetch_api_posts();

        //Calculamos la puntuación pasado la palabra a buscar y los post de la API
        $posts = $this->check_score($word, $posts);

        //Agregamos el nombre de usuario a los post con una llamada
        $posts = $this->set_user_name($posts);

        usort($posts, array('self','cmp'));

        //Exportamos a CSV
        $this->export_csv($posts);
    }

    //Recupera los post desde la API
    private function fetch_api_posts()
    {
        try {
            $response = Http::get('https://jsonplaceholder.typicode.com/posts');
        } catch(\Exception $e) {
            //Excepción general, habría que poner un log
            throw new \Exception('Fallo al recuperar los datos de la API');
        }

        //Si está vacío, devolvemos un error
        if(empty($response->body())) {
            throw new \Exception('Sin datos en la API');
        }

        return json_decode($response->body());
    }

    //Recupera los usuarios desde la API
    private function fetch_api_users()
    {
        try {
            $response = Http::get('https://jsonplaceholder.typicode.com/users');
        } catch(\Exception $e) {
            //Excepción general, habría que poner un log
            throw new \Exception('Fallo al recuperar los datos de la API');
        }

        //Si está vacío, devolvemos un error
        if(empty($response->body())) {
            throw new \Exception('Sin datos en la API');
        }

        return json_decode($response->body());
    }

    //Asignamos a cada post el nombre del usuario con una sola llamada a la API
    private function set_user_name($posts)
    {
        $users = $this->fetch_api_users();

        foreach ($posts as $key => $post) {
            foreach ($users as $user) {
                if($post->userId == $user->id) {
                    $posts[$key]->user_name = $user->name;
                    continue;
                }
            }
        }

        return $posts;
    }

    //Calcular la valoración de cada post
    private function check_score($word, $posts)
    {
        //Creamos array para almacenar la puntuación de cada usuario
        $scores = [];

        //Valoración de post
        foreach ($posts as $key => $post) {
            $_title = $post->title;
            $_body = $post->body;
            $_user = $post->userId;

            //Asignamos 2 puntos a la palabra puesta en el título y 1 si es en el cuerpo
            $_title_score = empty($_title) ? 0 : (substr_count($_title, $word) * 2);
            $_body_score = empty($_body) ? 0 : + (substr_count($_body, $word));

            //Puntuación del post (score)
            $_score = $_title_score + $_body_score;

            //Asignamos la puntuación al usuario
            $scores[$_user] = isset($scores[$_user]) ? $scores[$_user] + $_score : $_score;

            //Añadimos el campo al objecto
            $posts[$key]->score = $_score;
        }

        //Asignamos a cada usuario su puntuación total
        foreach ($scores as $user => $score) {
            foreach ($posts as $key => $post) {
                if($post->userId == $user) {
                    $posts[$key]->user_score = $score;
                    continue;
                }
            }
        }

        return $posts;
    }

    private function cmp($a, $b) {
        return
            ($b->user_score <=> $a->user_score) * 10 + // Total puntuación usuario
            ($b->score <=> $a->score); //Puntuación del post
    }

    private function export_csv($posts)
    {
        $fileName = 'posts.csv';

        $headers = array(
            "Content-type"        => "text/csv",
            "Content-Disposition" => "attachment; filename=$fileName",
            "Pragma"              => "no-cache",
            "Cache-Control"       => "must-revalidate, post-check=0, pre-check=0",
            "Expires"             => "0"
        );

        //Columnas del CSV
        $columns = array('Id Usuario', 'Nombre usuario', 'Valoración de usuario', 'Id Post', 'Valoración de post');

        $callback = function() use($posts, $columns) {
            $file = fopen('php://output', 'w');
            fputcsv($file, $columns);

            foreach ($posts as $post) {
                $row['Id Usuario']  = $post->userId;
                $row['Nombre usuario']    = $post->user_name;
                $row['Valoración de usuario']    = $post->user_score;
                $row['Id Post']  = $post->id;
                $row['Valoración de post']  = $post->score;

                fputcsv($file, array($row['Id Usuario'], $row['Nombre usuario'], $row['Valoración de usuario'], $row['Id Post'], $row['Valoración de post']));
            }

            fclose($file);
        };

        return Response::stream($callback, 200, $headers)->send();
    }
}
