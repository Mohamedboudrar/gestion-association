import http from "./http";

export const getDashboard = async () => {
    const { data } = await http.get("/dashboard");
    return data;
};