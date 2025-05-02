<?php

namespace App\Http\Controllers;

use App\Models\Categoria;
use App\Models\Peticione;
use App\Models\File;
use Exception;
use Illuminate\Http\Request;
use App\Http\Controllers\Auth;
use PhpParser\Node\Stmt\Return_;

/**
 * @OA\Tag(
 *     name="user",
 *     description="User related operations"
 * )
 * @OA\Info(
 *     version="1.0",
 *     title="Example API",
 *     description="Example info",
 *     @OA\Contact(name="Swagger API Team")
 * )
 * @OA\Server(
 *     url="https://example.localhost",
 *     description="API server"
 * )
 * @OA\Get(
 *     path="/api/users",
 *     @OA\Response(response="200", description="An example endpoint")
 * )
 */
class PeticioneController extends Controller
{

    public function __construct()
    {
        $this->middleware('auth:api', ['except' => ['index', 'show']]);
    }
    /**
     * @OA\Tag(name="index",description="Lista todas las peticiones")
     * @OA\Get(path="/api/peticiones",
     * @OA\Response(response="200",description="Todas las peticiones de la BBDD"))
     */
    public function index(Request $request)
    {
        try {
            $peticiones = Peticione::with('files')->paginate(3);
        } catch (Exception $e) {
            return response()->json(['Error' => 'Error buscando las peticiones', 'Debug' => $e->getMessage()], 404);
        }
        return response()->json(['Message' => 'Peticiones encontradas:', 'Data' => $peticiones]);
    }

    public function listMine()
    {
        try {
            $id = auth()->id();
            $peticiones = Peticione::with('files')->get()->where('user_id', '=', $id);
        } catch (Exception $e) {
            return response()->json(['Error' => 'Error buscando usuario', 'Debug' => $e->getMessage()], 404);
        }
        return response()->json(['Message' => 'Peticiones encontradas en función listMine:', 'Data' => $peticiones]);
    }

    public function listarFirmadas()
    {
        try {
            $peticiones = Peticione::with('files')->whereHas('firmas', function ($query) {
                $query->where('user_id', auth()->id());
            })->get();
        } catch (Exception) {
            return response()->json(['Error' => 'Error buscando peticiones'], 404);
        }
        if ($peticiones->count() < 0) {
            return response()->json(['Error' => 'Error buscando peticiones'], 404);
        }
        return response()->json(['Message' => 'Peticiones encontradas en función listarFirmadas:', 'Data' => $peticiones]);
    }
    public function show($id)
    {
        try {
            $peticion = Peticione::with('files')->findOrFail($id);
        } catch (Exception $e) {
            return response()->json(['Message' => 'Ha ocurrido un error','debug' => $e->getMessage(),], 404);
        }
        return response()->json(['Message' => 'Petición encontrada:', 'Data' => $peticion]);
    }

    public function update(Request $request, $id)
    {
        try {
            $peticion = Peticione::findOrFail($id);
            if ($request->user()->cannot('update', $peticion)) {
                return response()->json(['Error' => 'No estás autorizado para actualizar la petición.', 403]);
            }
            if ($peticion) {
                $input = $request->all();
                $peticion->update($input);
                // $peticion->save();
            }
        } catch (Exception $e) {
            return response()->json(['Error' => 'Error actualizando la petición', $e->getmessage()], 500);
        }
        //dd($request);
        return response()->json(["Message" => 'Petición actualizada', 'Datos' => $peticion, 'Debug' => $input], 200);
    }

    public
    function store(Request $request)
    {
        $this->validate($request, [
            'titulo' => 'required|max:255',
            'descripcion' => 'required',
            'destinatario' => 'required',
            'categoria_id' => 'required',
            'foto' => 'required',
        ]);
        $input = $request->all();
        try {
            $category = Categoria::query()->findOrFail($input['categoria_id']);
            $user = auth()->user(); //asociarlo al usuario autenticado
            $peticion = new Peticione($input);
            $peticion->categoria()->associate($category);
            $peticion->user()->associate($user);
            $peticion->firmantes = 0;
            $peticion->estado = 'pendiente';
            $res = $peticion->save();
            if ($res) {
                $res_file = $this->fileUpload($request, $peticion->id);
                if ($res_file) {
                    $peticion->file = $res_file;
                    return response()->json(
                        ['message' => 'Petición creada', 'data' => $peticion,],
                    );
                }
            }
        } catch (Exception $e) {
            return response()->json(
                ['error' => 'Error creando la petición', 'data' => $e->getMessage(), $e->getLine()],
            );
        }
        return response()->json(
            ['message' => 'Petición creada', 'data' => $res],
        );
    }

    public
    function fileUpload(Request $req, $peticione_id = null)
    {
        $input = $req->all();
        $files = $input['foto']; // Puede ser un array de archivos
        foreach ($files as $file) {
            if ($file->isValid()) {
                $fileModel = new File;
                $fileModel->peticione_id = $peticione_id;

                $filename = time() . '_' . $file->getClientOriginalName();
                try {
                    $file->move(public_path('images/peticiones/'), $filename);
                } catch (Exception $e) {
                    return response()->json(['error' => $e->getMessage()], 500);
                }

                $fileModel->name = $filename;
                $fileModel->file_path = $filename;
                $fileModel->save();

                $savedFiles[] = $fileModel;
            }
        }
        return $savedFiles;
    }

    function list(Request $request)
    {
        $peticiones = Peticione::jsonPaginate();
        return $peticiones;
    }

    public function firmar(Request $request, $id)
    {
        try {
            $peticion = Peticione::query()->findOrFail($id);
            if ($request->user()->cannot('firmar', $peticion)) {
                return response()->json(
                    ['message' => 'Ya has firmado esta petición'],
                    403
                );
            }
            $user_id = auth()->id();
            $peticion->firmas()->attach($user_id);
            $peticion->firmantes = $peticion->firmantes + 1;
            $peticion->save();
        } catch (\Exception $e) {
            return response()->json(['Error' => 'Ha ocurrido un error durante el firmado', "error_message" => $e->getMessage()]);
        }
        return response()->json(['Message' => 'Petición firmada', 'Data' => $peticion]);
    }

    public function cambiarEstado(Request $request, $id)
    {
        $peticion = Peticione::findOrFail($id);
        if ($request->user()->cannot('cambiarEstado', $peticion)) {
            return response()->json(
                ['message' => 'No estás autorizado para realizar esta acción'],
                403
            );
        }
        try {
            $peticion->estado = "Aceptada";
        } catch (Exception) {
            return response()->json(['message' => 'Ha ocurrido un error buscando la petición.']);
        }
        return response()->json(['message' => 'Estado cambiado', 'data' => $peticion]);
    }

    public function delete($id)
    {
        try {
            $peticion = Peticione::query()->findOrFail($id);
            $peticion->delete();
        } catch (Exception) {
            return response()->json(['Error' => 'Error encontrando la petición']);
        }
        return response()->json(['Message' => 'Petición eliminada']);
    }
}
